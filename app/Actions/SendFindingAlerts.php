<?php

namespace App\Actions;

use App\Enums\ChatNotificationKind;
use App\Models\Finding;
use App\Models\FindingEvent;
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
 * service gets the same issue; the daily digest is the reminder. Running it again sends nothing new.
 */
class SendFindingAlerts
{
    use Localizable;

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
        $groups = Finding::open()
            ->whereIn('severity', config('psm.alerts.immediate_severities'))
            ->where(fn ($query) => $query->whereNull('notified_severity')->orWhereColumn('notified_severity', '!=', 'severity'))
            ->get()
            ->filter(fn (Finding $finding): bool => $this->policy->awaitsImmediateAlert($finding))
            ->groupBy(fn (Finding $finding): string => "{$finding->team_id}|{$finding->group_key}");

        return $groups->sum(fn (Collection $findings): int => (int) $this->send($findings->first()->team_id, $findings->first()->group_key));
    }

    private function send(int $teamId, string $groupKey): bool
    {
        return DB::transaction(function () use ($teamId, $groupKey): bool {
            $findings = Finding::open()
                ->where('team_id', $teamId)
                ->where('group_key', $groupKey)
                ->with('deployment.service.project')
                ->lockForUpdate()
                ->get();

            $pending = $findings->filter(fn (Finding $finding): bool => $this->policy->awaitsImmediateAlert($finding));

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
