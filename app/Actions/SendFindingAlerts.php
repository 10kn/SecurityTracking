<?php

namespace App\Actions;

use App\Enums\ChatNotificationKind;
use App\Enums\FindingRule;
use App\Models\Advisory;
use App\Models\Finding;
use App\Models\FindingEvent;
use App\Models\Incident;
use App\Models\IncidentTeam;
use App\Models\IncidentTeamNotice;
use App\Models\Project;
use App\Models\Service;
use App\Models\Team;
use App\Services\AlertPolicy;
use App\Services\GoogleChatCards;
use App\Services\Kev\KnownExploitation;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Traits\Localizable;

/**
 * Sends the immediate alerts of handover 5.5: one card per team and issue, listing every service the issue affects.
 *
 * A finding is sent once at each severity. A team hears about an issue again only when its severity rises or another
 * service gets the same issue; the daily digest is the reminder. Running it again sends nothing new. A "thiếu bản vá"
 * finding a "Đã có bản vá" card stands for counts as sent without a card of its own (see isToldByIncident()), and a
 * new one may wait a few hours for that card (see isHeldForVendorPatch()).
 *
 * A service with many vulnerable packages to send at once, e.g. a new one, gets one short summary card instead of a
 * card per advisory (see reachesSummaryThreshold()); those listed by CISA KEV still get their own.
 *
 * The findings of a paused project are neither sent nor counted as sent (see Finding::monitored()): the team's
 * projects are share-locked while a card is queued, so pausing one waits for it, or the card for the pause.
 */
class SendFindingAlerts
{
    use Localizable;

    /**
     * @var array<string, bool>
     */
    private array $heldTechnologies = [];

    /**
     * @var array<int, list<string>>
     */
    private array $toldKnownExploited = [];

    public function __construct(
        private AlertPolicy $policy,
        private QueueChatNotification $queueChatNotification,
        private GoogleChatCards $cards,
    ) {}

    /**
     * @return int The number of cards queued.
     */
    public function handle(): int
    {
        [$covered, $pending] = Finding::open()
            ->monitored()
            ->whereIn('severity', config('psm.alerts.immediate_severities'))
            ->where(fn ($query) => $query->whereNull('notified_severity')->orWhereColumn('notified_severity', '!=', 'severity'))
            ->with(['runtime', 'deployment'])
            ->get()
            ->filter(fn (Finding $finding): bool => $this->policy->awaitsImmediateAlert($finding))
            ->partition(fn (Finding $finding): bool => $this->isToldByIncident($finding));

        $covered->each(fn (Finding $finding) => Finding::whereKey($finding->id)
            ->where('severity', $finding->severity)
            ->where('context->covered_by_patch_id', $finding->context['covered_by_patch_id'])
            ->update(['notified_severity' => $finding->severity, 'notified_at' => now()]));

        $pending = $pending->reject(fn (Finding $finding): bool => $this->isHeldForVendorPatch($finding));

        $summaries = $pending
            ->filter(fn (Finding $finding): bool => $this->isSummarizable($finding))
            ->groupBy(fn (Finding $finding): string => "{$finding->team_id}|{$finding->deployment->service_id}")
            ->filter(fn (Collection $findings): bool => $this->reachesSummaryThreshold($findings))
            ->sum(fn (Collection $findings): int => (int) $this->sendLockfileSummary($findings->first()->team_id, $findings->first()->deployment->service_id));

        // The findings a summary stood for are sent now, so their issues' cards leave them out of what is new (see send()).
        $groups = $pending->groupBy(fn (Finding $finding): string => "{$finding->team_id}|{$finding->group_key}");

        return $summaries + $groups->sum(fn (Collection $findings): int => (int) $this->send($findings->first()->team_id, $findings->first()->group_key));
    }

    /**
     * One card for the vulnerable packages of a service (see reachesSummaryThreshold()), read again under the same
     * locks as send(): a run that sent some of them meanwhile may leave too few for a summary, and the rest then get
     * their issues' cards. The findings are locked in id order, as in send(), so the two never wait on each other.
     */
    private function sendLockfileSummary(int $teamId, int $serviceId): bool
    {
        return DB::transaction(function () use ($teamId, $serviceId): bool {
            Project::where('team_id', $teamId)->orderBy('id')->sharedLock()->pluck('id');

            $pending = Finding::open()
                ->monitored()
                ->where('team_id', $teamId)
                ->where('rule', FindingRule::DependencyVulnerable)
                ->whereHas('deployment', fn ($query) => $query->where('service_id', $serviceId))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->filter(fn (Finding $finding): bool => $this->policy->awaitsImmediateAlert($finding) && ! $this->isToldByIncident($finding));

            [$summarized, $knownExploited] = $pending->partition(fn (Finding $finding): bool => $this->isSummarizable($finding));

            if (! $this->reachesSummaryThreshold($summarized)) {
                return false;
            }

            $team = Team::findOrFail($teamId);
            $service = Service::with('project')->findOrFail($serviceId);

            $payload = $this->withLocale(config('psm.alerts.locale'), fn (): array => $this->cards->lockfileSummary(
                $team,
                $service,
                $summarized,
                $knownExploited->pluck('group_key')->unique()->count(),
            ));

            $this->queueChatNotification->handle($team, ChatNotificationKind::Immediate, $this->dedupeKey($teamId, "lockfile-summary:{$serviceId}", $summarized), $payload);

            $summarized->each(fn (Finding $finding) => $finding->update(['notified_severity' => $finding->severity, 'notified_at' => now()]));

            return true;
        });
    }

    /**
     * A vulnerable package can go into a service's summary; one CISA KEV lists a CVE of keeps its own card, which
     * lists every service exploited the same way.
     */
    private function isSummarizable(Finding $finding): bool
    {
        return $finding->rule === FindingRule::DependencyVulnerable && ! $finding->isKnownExploited();
    }

    /**
     * Whether a service's findings to send are enough advisories for one summary (`psm.alerts.lockfile_summary_threshold`).
     * Advisories are counted, not findings: one advisory in several environments of the service is still one card.
     *
     * @param  Collection<int, Finding>  $findings
     */
    private function reachesSummaryThreshold(Collection $findings): bool
    {
        $threshold = (int) config('psm.alerts.lockfile_summary_threshold');

        return $threshold > 0 && $findings->pluck('group_key')->unique()->count() >= $threshold;
    }

    private function send(int $teamId, string $groupKey): bool
    {
        return DB::transaction(function () use ($teamId, $groupKey): bool {
            Project::where('team_id', $teamId)->orderBy('id')->sharedLock()->pluck('id');

            $findings = Finding::open()
                ->monitored()
                ->where('team_id', $teamId)
                ->where('group_key', $groupKey)
                ->with(['deployment.service.project', 'runtime'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $pending = $findings->filter(fn (Finding $finding): bool => $this->policy->awaitsImmediateAlert($finding)
                && ! $this->isToldByIncident($finding)
                && ! $this->isHeldForVendorPatch($finding));

            if ($pending->isEmpty()) {
                return false;
            }

            $team = Team::findOrFail($teamId);

            $payload = $this->withLocale(config('psm.alerts.locale'), fn (): array => $this->cards->findingAlert($team, $findings, $pending->modelKeys()));

            $this->queueChatNotification->handle($team, ChatNotificationKind::Immediate, $this->dedupeKey($teamId, $groupKey, $pending), $payload);

            $pending->each(fn (Finding $finding) => $finding->update(['notified_severity' => $finding->severity, 'notified_at' => now()]));

            return true;
        });
    }

    /**
     * A "thiếu bản vá" finding a "Đã có bản vá" card stands for (see CoverPatchedFindings) is told by the incident's
     * thread. When CISA KEV lists CVE ids of it, the team must also have been told about each of them in an incident
     * thread (see SendPreNoticeAlerts); otherwise the finding gets a card of its own.
     */
    private function isToldByIncident(Finding $finding): bool
    {
        if (! isset($finding->context['covered_by_patch_id'])) {
            return false;
        }

        $this->toldKnownExploited[$finding->team_id] ??= IncidentTeam::where('team_id', $finding->team_id)
            ->pluck('told_known_exploited')
            ->flatten()
            ->filter(fn (mixed $cve): bool => is_string($cve))
            ->unique()
            ->values()
            ->all();

        return array_diff(KnownExploitation::cves($finding->context['known_exploited'] ?? null), $this->toldKnownExploited[$finding->team_id]) === [];
    }

    /**
     * A new "thiếu bản vá" finding waits up to `psm.incidents.patch_hold_hours` while the team has been warned of a
     * current incident of the technology: the vendor's pre-notice usually names the fixed version soon after the
     * release, and its "Đã có bản vá" card then stands for the finding (see CoverPatchedFindings). The team already
     * has the incident's card meanwhile, or a card from before incidents; after the wait the finding is sent as before.
     * An incident an admin validly assessed as not affecting the team ("Không") never sends it that card, so it holds
     * nothing back; nor does one limited to operating systems the finding's runtime does not run (see
     * Incident::excludedBy()), as it confirms no fix for it.
     */
    private function isHeldForVendorPatch(Finding $finding): bool
    {
        if ($finding->rule !== FindingRule::RuntimeOutdatedSecurity || $finding->runtime === null || $finding->first_seen_at->lt(now()->subHours(config('psm.incidents.patch_hold_hours')))) {
            return false;
        }

        $runtime = $finding->runtime;

        return $this->heldTechnologies["{$finding->team_id}:{$runtime->technology->value}:{$runtime->deployment?->os?->value}"] ??= IncidentTeamNotice::firstToldAt(
            $finding->team_id,
            Advisory::alertable()
                ->where('technology', $finding->runtime->technology)
                ->get(['id', 'incident_id'])
                ->groupBy('incident_id')
                ->reject(fn (Collection $notices, int|string $incidentId): bool => $incidentId !== '' && ($incident = Incident::find($incidentId)) !== null && ($incident->excludedBy($runtime) !== null || IncidentTeam::isRuledOutFor($incident, $finding->team_id)))
                ->collapse()
                ->pluck('id')
                ->all(),
        ) !== null;
    }

    /**
     * The same findings at the same severity give the same key. The latest finding event tells a reopened finding
     * apart from its earlier occurrence.
     *
     * @param  EloquentCollection<int, Finding>  $pending
     */
    private function dedupeKey(int $teamId, string $groupKey, EloquentCollection $pending): string
    {
        $latestEventId = FindingEvent::whereIn('finding_id', $pending->modelKeys())->max('id');

        $findings = $pending
            ->map(fn (Finding $finding): string => "{$finding->id}:{$finding->severity->value}")
            ->sort()
            ->join(',');

        return "immediate:{$teamId}:".hash('sha256', "{$groupKey}|{$findings}|{$latestEventId}");
    }
}
