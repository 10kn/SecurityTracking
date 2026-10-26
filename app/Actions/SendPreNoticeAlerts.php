<?php

namespace App\Actions;

use App\Enums\ChatNotificationKind;
use App\Enums\IncidentAlertReason;
use App\Enums\Severity;
use App\Models\Advisory;
use App\Models\Incident;
use App\Models\IncidentTeamNotice;
use App\Models\IncidentTeamPatch;
use App\Models\Runtime;
use App\Services\AlertPolicy;
use App\Services\GoogleChatCards;
use App\Support\IncidentLock;
use App\Support\Version;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Traits\Localizable;

/**
 * Sends "Cảnh báo chuẩn bị" (handover 5.2) per incident: every team that runs the technology gets one card per
 * incident, listing its services on that technology with whether the vendor said their branch is affected. Only
 * pre-notices published within `psm.notices.relevant_days` count, and never those a feed listed on its first poll
 * (see SyncNoticeFeed). A team that starts running the technology later gets the card on a later run while the
 * incident is still within that window. Running it again sends nothing new.
 *
 * An incident is sent right away when one of its pre-notices is (Critical, High or of unknown severity, see
 * AlertPolicy): a Low one never hides one the classifier has not rated. Incidents of Medium and Low pre-notices only
 * wait for the daily digest, and pre-notices the classifier dismissed as not security news do not count. Pre-notices
 * only notify: they never open or escalate findings, since the vendor rarely says which branches are affected.
 *
 * Each card records which pre-notices it told the team about, at which severity (`incident_team_notices`), in the
 * transaction that queues it. A team hears about an incident again only when its highest severity rises above what its
 * pre-notices were sent at, or when the vendor confirms the fix for a branch it runs: one "Đã có bản vá" card per fix
 * (see Incident::confirmedPatches()), which is also the first card of an incident the team had not heard of. News that
 * only adds to an incident does not send another card. A security release no pre-notice names is not a fix yet: it
 * only appears in the digest.
 *
 * The fix often comes after `psm.notices.relevant_days`. A team that was told about the incident still gets its
 * "Đã có bản vá" card until `psm.incidents.awaiting_patch_days` after the incident began, with every pre-notice of the
 * incident that counts at all (see lateNotices()); nothing else is sent for such an incident.
 */
class SendPreNoticeAlerts
{
    use Localizable;

    public function __construct(
        private AlertPolicy $policy,
        private QueueChatNotification $queueChatNotification,
        private GoogleChatCards $cards,
        private AssignNoticeToIncident $assignNoticeToIncident,
        private CoverPatchedFindings $coverPatchedFindings,
    ) {}

    /**
     * @param  bool  $holdUnclassified  Leaves out pre-notices below Critical the classifier has not read yet: they wait
     *                                  for it, as it may raise them, while Critical ones go out right away.
     * @return int The number of cards queued.
     */
    public function handle(bool $holdUnclassified = false): int
    {
        $this->assignNoticeToIncident->assignUnassigned();

        $incidentIds = Advisory::alertable()->whereNotNull('incident_id')->distinct()->pluck('incident_id');
        $awaitingPatchIds = Incident::current()
            ->where('first_published_at', '>=', now()->subDays(config('psm.incidents.awaiting_patch_days')))
            ->whereHas('notices', fn ($query) => $query->whereNotNull('patch_versions'))
            ->pluck('id');

        return Incident::whereKey($incidentIds->merge($awaitingPatchIds)->unique()->values())
            ->orderBy('first_published_at')
            ->orderBy('id')
            ->get()
            ->sum(fn (Incident $incident): int => $this->send($incident, $holdUnclassified));
    }

    /**
     * The incident row is locked while its cards are queued, so an admin cannot merge or split it meanwhile. The
     * technology's IncidentLock comes first, so an evaluation storing a "thiếu bản vá" finding waits for the
     * "Đã có bản vá" card that stands for it, or the card waits for the finding (see SyncFindings).
     */
    private function send(Incident $incident, bool $holdUnclassified): int
    {
        return DB::transaction(function () use ($incident, $holdUnclassified): int {
            IncidentLock::take([$incident->technology]);

            $incident = Incident::whereKey($incident->id)->lockForUpdate()->first();

            if ($incident === null || $incident->merged_into_id !== null) {
                return 0;
            }

            $notices = $this->countingNotices(Advisory::alertable()->where('incident_id', $incident->id), $holdUnclassified);
            $isLate = ! $this->isUrgent($notices);

            if ($isLate) {
                $notices = $this->lateNotices($incident, $holdUnclassified);

                if (! $this->isUrgent($notices)) {
                    return 0;
                }
            }

            $memberIds = $incident->notices()->pluck('id')->all();
            $patches = $incident->confirmedPatches();

            if ($isLate && $patches === []) {
                return 0;
            }

            $runtimesByTeam = Runtime::where('technology', $incident->technology)
                ->whereHas('deployment', fn ($query) => $query->where('is_current', true))
                ->with('deployment.service.project.team')
                ->get()
                ->groupBy(fn (Runtime $runtime): int => $runtime->deployment->service->project->team_id);

            $queued = 0;

            foreach ($runtimesByTeam as $teamId => $runtimes) {
                $toldSeverity = IncidentTeamNotice::severityToldTo($teamId, $memberIds);

                if ($isLate && $toldSeverity === null) {
                    continue;
                }

                $duePatches = $this->duePatches($teamId, $runtimes, $patches, $memberIds);
                $isRaised = ! $isLate && ($toldSeverity === null || $toldSeverity->rank() < Incident::highestSeverity($notices)->rank());

                if ($duePatches === [] && ! $isRaised) {
                    continue;
                }

                $queued += (int) $this->queue($incident, $notices, $runtimes, $isRaised ? $toldSeverity : null, $toldSeverity === null, $duePatches, $patches);
            }

            return $queued;
        });
    }

    /**
     * The pre-notices of the query that count now, oldest first.
     *
     * @param  Builder<Advisory>  $query
     * @return Collection<int, Advisory>
     */
    private function countingNotices(Builder $query, bool $holdUnclassified): Collection
    {
        return $query->orderBy('published_at')
            ->orderBy('id')
            ->get()
            ->reject(fn (Advisory $notice): bool => $holdUnclassified && $notice->severity !== Severity::Critical && $notice->classified_by === null)
            ->values();
    }

    /**
     * Whether the incident is sent right away: one of its pre-notices is (see AlertPolicy::sendsPreNoticeRightAway()).
     *
     * @param  Collection<int, Advisory>  $notices
     */
    private function isUrgent(Collection $notices): bool
    {
        return $notices->contains(fn (Advisory $notice): bool => $this->policy->sendsPreNoticeRightAway($notice));
    }

    /**
     * The pre-notices of an incident no longer within `psm.notices.relevant_days` that still count for its
     * "Đã có bản vá" card: those not dismissed nor listed on a feed's first poll, while the incident began within
     * `psm.incidents.awaiting_patch_days`. None after that.
     *
     * @return Collection<int, Advisory>
     */
    private function lateNotices(Incident $incident, bool $holdUnclassified): Collection
    {
        if ($incident->first_published_at === null || $incident->first_published_at->lt(now()->subDays(config('psm.incidents.awaiting_patch_days')))) {
            return collect();
        }

        return $this->countingNotices($incident->notices()->getQuery()->where('is_dismissed', false)->where('is_backfill', false), $holdUnclassified);
    }

    /**
     * The fixes the team has not had a "Đã có bản vá" card for: a branch it runs below the fixed version, with no card
     * from a pre-notice of the incident for that version or a later one. A runtime whose version is not known counts
     * as below.
     *
     * @param  Collection<int, Runtime>  $runtimes
     * @param  array<string, array{version: string, notice: Advisory}>  $patches
     * @param  list<int>  $memberIds
     * @return array<string, array{version: string, notice: Advisory}>
     */
    private function duePatches(int $teamId, Collection $runtimes, array $patches, array $memberIds): array
    {
        return collect($patches)
            ->filter(fn (array $patch, string $branch): bool => $runtimes->contains(fn (Runtime $runtime): bool => $runtime->branch === $branch && ($runtime->version === null || Version::isNewer($patch['version'], $runtime->version))))
            ->reject(fn (array $patch, string $branch): bool => IncidentTeamPatch::where('team_id', $teamId)
                ->where('branch', $branch)
                ->whereIn('advisory_id', $memberIds)
                ->pluck('version')
                ->contains(fn (string $version): bool => ! Version::isNewer($patch['version'], $version)))
            ->all();
    }

    /**
     * The card leads with the most severe pre-notice and lists the others up to `psm.alerts.max_listed_items`, most
     * severe first. Only the pre-notices it shows are recorded as told. With fixes due, it is the "Đã có bản vá" card,
     * which also stands for the incident's own card and for the team's "thiếu bản vá" findings of those branches (see
     * CoverPatchedFindings), with the deadline they share: that of the incident's severity from now, or the earliest of
     * those findings.
     *
     * @param  Collection<int, Advisory>  $notices  The incident's pre-notices that count now, oldest first.
     * @param  Collection<int, Runtime>  $runtimes  One team's runtimes of the technology.
     * @param  Severity|null  $previousSeverity  What the team was told before, when the incident rose since.
     * @param  array<string, array{version: string, notice: Advisory}>  $duePatches
     * @param  array<string, array{version: string, notice: Advisory}>  $patches  Every fix of the incident (see Incident::confirmedPatches()).
     */
    private function queue(Incident $incident, Collection $notices, Collection $runtimes, ?Severity $previousSeverity, bool $isNew, array $duePatches, array $patches): bool
    {
        $team = $runtimes->first()->deployment->service->project->team;
        $lead = $notices->sortByDesc(fn (Advisory $notice): int => $notice->severity->rank())->first();
        $others = $notices->reject(fn (Advisory $notice): bool => $notice->is($lead))->sortByDesc(fn (Advisory $notice): int => $notice->severity->rank())->take(config('psm.alerts.max_listed_items'))->values();
        $shown = collect([$lead, ...$others]);
        $severity = Incident::highestSeverity($notices);
        $versions = collect($duePatches)->map(fn (array $patch, string $branch): string => "{$branch}:{$patch['version']}")->sort()->join(',');
        $dedupeKey = "pre_notice:incident:{$incident->id}:{$team->id}:".hash('sha256', $shown->map(fn (Advisory $notice): string => "{$notice->id}:{$notice->severity->value}")->sort()->join(',')."|{$versions}");
        $covered = collect($duePatches)->map(fn (array $patch, string $branch) => $this->coverPatchedFindings->findingsFor($team->id, $incident->technology, $branch, $patch['version']));
        $deadline = $this->coverPatchedFindings->deadlineFor($severity, new EloquentCollection($covered->collapse()->all()));

        $versionsOf = fn (array $patches): array => collect($patches)->map(fn (array $patch): string => $patch['version'])->all();

        $payload = $this->withLocale(config('psm.alerts.locale'), fn (): array => $duePatches === []
            ? $this->cards->preNotice($team, $incident, $lead, $others, $runtimes, $previousSeverity)
            : $this->cards->patchAvailable($team, $incident, $lead, $others, $runtimes, $versionsOf($duePatches), $versionsOf($patches), $previousSeverity, $deadline));

        $notification = $this->queueChatNotification->handle($team, ChatNotificationKind::PreNotice, $dedupeKey, $payload);

        if ($notification === null) {
            return false;
        }

        $reason = match (true) {
            $duePatches !== [] => IncidentAlertReason::PatchConfirmed,
            $isNew => IncidentAlertReason::New,
            default => IncidentAlertReason::SeverityRise,
        };

        IncidentTeamNotice::insertOrIgnore($shown->map(fn (Advisory $notice): array => [
            'team_id' => $team->id,
            'advisory_id' => $notice->id,
            'severity' => $notice->severity->value,
            'reason' => $reason->value,
            'chat_notification_id' => $notification->id,
            'created_at' => now(),
        ])->all());

        foreach ($duePatches as $branch => $patch) {
            $this->coverPatchedFindings->handle(IncidentTeamPatch::create([
                'team_id' => $team->id,
                'advisory_id' => $patch['notice']->id,
                'technology' => $incident->technology,
                'branch' => $branch,
                'version' => $patch['version'],
                'severity' => $severity,
                'chat_notification_id' => $notification->id,
            ]), $covered[$branch]);
        }

        return true;
    }
}
