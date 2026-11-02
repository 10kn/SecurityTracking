<?php

namespace App\Actions;

use App\Enums\ChatNotificationKind;
use App\Enums\FindingEventType;
use App\Enums\IncidentScope;
use App\Enums\LockfileFormat;
use App\Enums\ProjectEventType;
use App\Models\Finding;
use App\Models\Incident;
use App\Models\IncidentTeam;
use App\Models\Lockfile;
use App\Models\Project;
use App\Models\ProjectEvent;
use App\Models\ProjectIncidentSkip;
use App\Models\Runtime;
use App\Models\Service;
use App\Models\User;
use App\Services\AlertPolicy;
use App\Services\GoogleChatCards;
use App\Services\IncidentTeamExposure;
use App\Services\Kev\KnownExploitation;
use App\Support\IncidentLock;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Traits\Localizable;

/**
 * An admin resumes the monitoring of a paused project, choosing whether its team hears what it missed meanwhile.
 * Either way:
 *
 * - the deadline of each open finding of the project starts again from today, as if it was first seen now, though
 *   never later than the date CISA KEV asks one of its CVEs fixed by: SyncFindings brings deadlines forward to that
 *   date, so the next scan leaves it as it is;
 * - an admin's assessment ("Đã đọc" with its impact) of a current incident affecting the project's services is reset
 *   for the team: the project was not maintained meanwhile, so it has to be assessed again.
 *
 * "Gửi" ($notify) sends the team one card with the project's state now (see GoogleChatCards::projectResumed()); its
 * findings not told yet and the incidents it is exposed to then go out with the next alert runs, as for any project.
 *
 * "Không gửi" sends nothing about what is there now: every open finding of the project counts as told at its severity
 * and is marked as read by the admin, and each current incident affecting its services keeps a snapshot of what it
 * asks of them (see ProjectIncidentSkip), so its alerts leave them out until it asks more. Anything new after that is
 * sent as usual.
 *
 * Locks are taken as PauseProjectMonitoring takes them.
 */
class ResumeProjectMonitoring
{
    use Localizable;

    public function __construct(
        private AlertPolicy $policy,
        private QueueChatNotification $queueChatNotification,
        private GoogleChatCards $cards,
        private SendDailyDigest $sendDailyDigest,
    ) {}

    /**
     * @return bool Whether it was resumed; false when it was not paused.
     */
    public function handle(User $user, Project $project, bool $notify, ?string $note): bool
    {
        return DB::transaction(function () use ($user, $project, $notify, $note): bool {
            IncidentLock::take($project->currentTechnologies());

            $project = Project::with('team')->whereKey($project->id)->lockForUpdate()->firstOrFail();

            if (! $project->isPaused()) {
                return false;
            }

            $event = $project->events()->create([
                'type' => ProjectEventType::Resumed,
                'user_id' => $user->id,
                'data' => ['notify' => $notify, 'note' => $note, 'paused_at' => $project->monitoring_paused_at->toIso8601String(), 'reason' => $project->monitoring_pause_reason, 'user' => $user->name],
            ]);
            $project->update(['monitoring_paused_at' => null, 'monitoring_paused_by' => null, 'monitoring_pause_reason' => null]);
            ProjectIncidentSkip::where('project_id', $project->id)->delete();

            $findings = Finding::open()
                ->whereHas('deployment.service', fn ($query) => $query->where('project_id', $project->id))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $findings->each(fn (Finding $finding) => $finding->update(['sla_due_at' => $this->restartedDeadline($finding)]));

            $runtimes = Runtime::monitored()
                ->whereHas('deployment.service', fn ($query) => $query->where('project_id', $project->id))
                ->with('deployment.service.project')
                ->orderBy('id')
                ->get();
            $incidents = $this->resetIncidents($user, $project, $runtimes, $notify);

            if ($notify) {
                $this->tell($user, $project, $event, $note, $findings, $incidents, $runtimes);
            } else {
                $findings->each(function (Finding $finding) use ($user): void {
                    $finding->update([
                        'notified_severity' => $finding->severity,
                        'notified_at' => now(),
                        'acknowledged_at' => $finding->acknowledged_at ?? now(),
                        'acknowledged_by' => $finding->acknowledged_by ?? $user->id,
                    ]);
                    $finding->events()->create(['type' => FindingEventType::Acknowledged, 'data' => ['user_id' => $user->id, 'user' => $user->name, 'note' => __('Bỏ qua khi mở lại project')]]);
                });
            }

            return true;
        });
    }

    /**
     * The deadline of the finding's severity from now, never later than its CISA KEV due date nor earlier than the one
     * it had. A severity without a deadline keeps the one it had.
     */
    private function restartedDeadline(Finding $finding): ?CarbonInterface
    {
        $restarted = $this->policy->deadlineFor($finding->severity, now());

        if ($restarted === null) {
            return $finding->sla_due_at;
        }

        $deadline = $finding->sla_due_at !== null && $finding->sla_due_at->gt($restarted) ? $finding->sla_due_at : $restarted;
        $dueDate = KnownExploitation::dueDate($finding->context['known_exploited'] ?? null);

        return $dueDate !== null && $dueDate->lt($deadline) ? $dueDate : $deadline;
    }

    /**
     * Resets the team's assessment of each current incident affecting the project's services and, without telling the
     * team, records what it asks of them.
     *
     * @param  EloquentCollection<int, Runtime>  $runtimes  The project's runtimes on current deployments.
     * @return Collection<int, Incident> The incidents affecting the project's services, most severe first.
     */
    private function resetIncidents(User $user, Project $project, EloquentCollection $runtimes, bool $notify): Collection
    {
        if ($runtimes->isEmpty()) {
            return collect();
        }

        return Incident::current()
            ->whereIn('technology', $runtimes->pluck('technology.value')->unique()->values())
            ->orderBy('id')
            ->get()
            ->filter(function (Incident $incident) use ($user, $project, $runtimes, $notify): bool {
                $notices = $incident->notices()->get();
                $patches = $incident->confirmedPatches($notices);
                $exposure = IncidentTeamExposure::of($incident, $notices, new EloquentCollection($runtimes->where('technology', $incident->technology)->values()->all()), $patches);

                if (! collect($exposure->toArray()['services'])->contains(fn (array $service): bool => $service['scope'] !== IncidentScope::NotAffected->value)) {
                    return false;
                }

                IncidentTeam::where('incident_id', $incident->id)
                    ->where('team_id', $project->team_id)
                    ->lockForUpdate()
                    ->first()
                    ?->resetForResumedProject($project, $user, IncidentTeamExposure::of($incident, $notices, IncidentTeamExposure::runtimesOf($incident, $project->team_id), $patches));

                if (! $notify) {
                    ProjectIncidentSkip::create(['project_id' => $project->id, 'incident_id' => $incident->id, 'exposure' => $exposure->toArray(), 'user_id' => $user->id]);
                }

                return true;
            })
            ->sortByDesc(fn (Incident $incident): int => $incident->severity->rank())
            ->values();
    }

    /**
     * @param  EloquentCollection<int, Finding>  $findings
     * @param  Collection<int, Incident>  $incidents
     * @param  EloquentCollection<int, Runtime>  $runtimes
     */
    private function tell(User $user, Project $project, ProjectEvent $event, ?string $note, EloquentCollection $findings, Collection $incidents, EloquentCollection $runtimes): void
    {
        $unreadable = Service::with('productionDeployment.lockfiles')
            ->where('project_id', $project->id)
            ->get()
            ->sum(function (Service $service): int {
                $lockfiles = $service->productionDeployment->lockfiles ?? collect();
                $superseded = LockfileFormat::supersededPaths($lockfiles->pluck('path'));

                return $lockfiles->filter(fn (Lockfile $lockfile): bool => $lockfile->isUnreadable() && ! isset($superseded[$lockfile->path]))->count();
            });

        $payload = $this->withLocale(config('psm.alerts.locale'), fn (): array => $this->cards->projectResumed(
            $project,
            $user,
            $note,
            $findings,
            $incidents,
            $this->sendDailyDigest->endingSoon($runtimes, null),
            $unreadable,
        ));

        $this->queueChatNotification->handle($project->team, ChatNotificationKind::ProjectResumed, "project-resumed:{$project->id}:{$event->id}", $payload);
    }
}
