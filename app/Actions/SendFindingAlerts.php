<?php

namespace App\Actions;

use App\Enums\ChatNotificationKind;
use App\Enums\FindingRule;
use App\Models\Advisory;
use App\Models\Finding;
use App\Models\FindingEvent;
use App\Models\IncidentTeamNotice;
use App\Models\Team;
use App\Services\AlertPolicy;
use App\Services\GoogleChatCards;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Traits\Localizable;

/**
 * Sends the immediate alerts of handover 5.5: one card per team and issue, listing every service the issue affects.
 *
 * A finding is sent once at each severity. A team hears about an issue again only when its severity rises or another
 * service gets the same issue; the daily digest is the reminder. Running it again sends nothing new. A "thiếu bản vá"
 * finding a "Đã có bản vá" card stands for counts as sent without a card of its own, and a new one may wait a few
 * hours for that card (see isHeldForVendorPatch()).
 */
class SendFindingAlerts
{
    use Localizable;

    /**
     * @var array<string, bool>
     */
    private array $heldTechnologies = [];

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
            ->whereIn('severity', config('psm.alerts.immediate_severities'))
            ->where(fn ($query) => $query->whereNull('notified_severity')->orWhereColumn('notified_severity', '!=', 'severity'))
            ->with('runtime')
            ->get()
            ->filter(fn (Finding $finding): bool => $this->policy->awaitsImmediateAlert($finding))
            ->partition(fn (Finding $finding): bool => isset($finding->context['covered_by_patch_id']));

        $covered->each(fn (Finding $finding) => Finding::whereKey($finding->id)
            ->where('severity', $finding->severity)
            ->where('context->covered_by_patch_id', $finding->context['covered_by_patch_id'])
            ->update(['notified_severity' => $finding->severity, 'notified_at' => now()]));

        $groups = $pending
            ->reject(fn (Finding $finding): bool => $this->isHeldForVendorPatch($finding))
            ->groupBy(fn (Finding $finding): string => "{$finding->team_id}|{$finding->group_key}");

        return $groups->sum(fn (Collection $findings): int => (int) $this->send($findings->first()->team_id, $findings->first()->group_key));
    }

    private function send(int $teamId, string $groupKey): bool
    {
        return DB::transaction(function () use ($teamId, $groupKey): bool {
            $findings = Finding::open()
                ->where('team_id', $teamId)
                ->where('group_key', $groupKey)
                ->with(['deployment.service.project', 'runtime'])
                ->lockForUpdate()
                ->get();

            $pending = $findings->filter(fn (Finding $finding): bool => $this->policy->awaitsImmediateAlert($finding)
                && ! isset($finding->context['covered_by_patch_id'])
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
     * A new "thiếu bản vá" finding waits up to `psm.incidents.patch_hold_hours` while the team has been warned of a
     * current incident of the technology: the vendor's pre-notice usually names the fixed version soon after the
     * release, and its "Đã có bản vá" card then stands for the finding (see CoverPatchedFindings). The team already
     * has the incident's card meanwhile, or a card from before incidents; after the wait the finding is sent as before.
     */
    private function isHeldForVendorPatch(Finding $finding): bool
    {
        if ($finding->rule !== FindingRule::RuntimeOutdatedSecurity || $finding->runtime === null || $finding->first_seen_at->lt(now()->subHours(config('psm.incidents.patch_hold_hours')))) {
            return false;
        }

        return $this->heldTechnologies["{$finding->team_id}:{$finding->runtime->technology->value}"] ??= IncidentTeamNotice::firstToldAt(
            $finding->team_id,
            Advisory::alertable()->where('technology', $finding->runtime->technology)->pluck('id')->all(),
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
