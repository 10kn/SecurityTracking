<?php

namespace App\Actions;

use App\Enums\ChatNotificationKind;
use App\Enums\IncidentAlertReason;
use App\Enums\Severity;
use App\Models\Advisory;
use App\Models\Incident;
use App\Models\IncidentTeamNotice;
use App\Models\Runtime;
use App\Services\AlertPolicy;
use App\Services\GoogleChatCards;
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
 * pre-notices were sent at; news that only adds to an incident does not send another card.
 */
class SendPreNoticeAlerts
{
    use Localizable;

    public function __construct(
        private AlertPolicy $policy,
        private QueueChatNotification $queueChatNotification,
        private GoogleChatCards $cards,
        private AssignNoticeToIncident $assignNoticeToIncident,
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

        return Incident::whereKey($incidentIds)
            ->orderBy('first_published_at')
            ->orderBy('id')
            ->get()
            ->sum(fn (Incident $incident): int => $this->send($incident, $holdUnclassified));
    }

    /**
     * The incident row is locked while its cards are queued, so an admin cannot merge or split it meanwhile.
     */
    private function send(Incident $incident, bool $holdUnclassified): int
    {
        return DB::transaction(function () use ($incident, $holdUnclassified): int {
            $incident = Incident::whereKey($incident->id)->lockForUpdate()->first();

            if ($incident === null || $incident->merged_into_id !== null) {
                return 0;
            }

            $notices = Advisory::alertable()
                ->where('incident_id', $incident->id)
                ->orderBy('published_at')
                ->orderBy('id')
                ->get()
                ->reject(fn (Advisory $notice): bool => $holdUnclassified && $notice->severity !== Severity::Critical && $notice->classified_by === null)
                ->values();
            $urgent = $notices->filter(fn (Advisory $notice): bool => $this->policy->sendsPreNoticeRightAway($notice));

            if ($urgent->isEmpty()) {
                return 0;
            }

            $memberIds = $incident->notices()->pluck('id')->all();

            $runtimesByTeam = Runtime::where('technology', $incident->technology)
                ->whereHas('deployment', fn ($query) => $query->where('is_current', true))
                ->with('deployment.service.project.team')
                ->get()
                ->groupBy(fn (Runtime $runtime): int => $runtime->deployment->service->project->team_id);

            $queued = 0;

            foreach ($runtimesByTeam as $teamId => $runtimes) {
                $toldSeverity = IncidentTeamNotice::severityToldTo($teamId, $memberIds);

                if ($toldSeverity !== null && $toldSeverity->rank() >= Incident::highestSeverity($notices)->rank()) {
                    continue;
                }

                $queued += (int) $this->queue($incident, $notices, $runtimes, $toldSeverity);
            }

            return $queued;
        });
    }

    /**
     * The card leads with the most severe pre-notice and lists the others up to `psm.alerts.max_listed_items`, most
     * severe first. Only the pre-notices it shows are recorded as told.
     *
     * @param  Collection<int, Advisory>  $notices  The incident's pre-notices that count now, oldest first.
     * @param  Collection<int, Runtime>  $runtimes  One team's runtimes of the technology.
     */
    private function queue(Incident $incident, Collection $notices, Collection $runtimes, ?Severity $toldSeverity): bool
    {
        $team = $runtimes->first()->deployment->service->project->team;
        $lead = $notices->sortByDesc(fn (Advisory $notice): int => $notice->severity->rank())->first();
        $others = $notices->reject(fn (Advisory $notice): bool => $notice->is($lead))->sortByDesc(fn (Advisory $notice): int => $notice->severity->rank())->take(config('psm.alerts.max_listed_items'))->values();
        $shown = collect([$lead, ...$others]);
        $dedupeKey = "pre_notice:incident:{$incident->id}:{$team->id}:".hash('sha256', $shown->map(fn (Advisory $notice): string => "{$notice->id}:{$notice->severity->value}")->sort()->join(','));

        $payload = $this->withLocale(config('psm.alerts.locale'), fn (): array => $this->cards->preNotice($team, $incident, $lead, $others, $runtimes, $toldSeverity));

        $notification = $this->queueChatNotification->handle($team, ChatNotificationKind::PreNotice, $dedupeKey, $payload);

        if ($notification === null) {
            return false;
        }

        IncidentTeamNotice::insertOrIgnore($shown->map(fn (Advisory $notice): array => [
            'team_id' => $team->id,
            'advisory_id' => $notice->id,
            'severity' => $notice->severity->value,
            'reason' => ($toldSeverity === null ? IncidentAlertReason::New : IncidentAlertReason::SeverityRise)->value,
            'chat_notification_id' => $notification->id,
            'created_at' => now(),
        ])->all());

        return true;
    }
}
