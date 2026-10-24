<?php

namespace App\Actions;

use App\Enums\AdvisoryKind;
use App\Enums\ChatNotificationKind;
use App\Models\Advisory;
use App\Models\ChatNotification;
use App\Models\Runtime;
use App\Services\GoogleChatCards;
use Illuminate\Support\Collection;
use Illuminate\Support\Traits\Localizable;

/**
 * Sends "Cảnh báo chuẩn bị" (handover 5.2): every team that runs the technology of a fresh pre-notice gets one card
 * per pre-notice, listing its services on that technology. Pre-notices older than `psm.notices.max_age_hours` are not
 * sent, so the first poll does not replay a feed's history. A team that starts running the technology later gets the
 * card on a later run while the pre-notice is still fresh. Running it again sends nothing new.
 *
 * Pre-notices only notify and never open findings, so FindingRule::PreNotice stays unused. A critical pre-notice
 * raises the severity of runtime findings instead (see RuntimeFindings).
 */
class SendPreNoticeAlerts
{
    use Localizable;

    public function __construct(private QueueChatNotification $queueChatNotification, private GoogleChatCards $cards) {}

    /**
     * @return int The number of cards queued.
     */
    public function handle(): int
    {
        return Advisory::where('kind', AdvisoryKind::PreNotice)
            ->whereNotNull('technology_id')
            ->where('published_at', '>=', now()->subHours(config('psm.notices.max_age_hours')))
            ->with('technology')
            ->orderBy('published_at')
            ->get()
            ->sum(fn (Advisory $advisory): int => $this->send($advisory));
    }

    private function send(Advisory $advisory): int
    {
        $runtimesByTeam = Runtime::where('technology_id', $advisory->technology_id)
            ->whereHas('deployment', fn ($query) => $query->where('is_current', true))
            ->with(['technology', 'deployment.service.project.team'])
            ->get()
            ->groupBy(fn (Runtime $runtime): int => $runtime->deployment->service->project->team_id);

        $queued = 0;

        foreach ($runtimesByTeam as $teamId => $runtimes) {
            $dedupeKey = "pre_notice:{$advisory->id}:{$teamId}";

            if (ChatNotification::where('dedupe_key', $dedupeKey)->exists()) {
                continue;
            }

            $queued += (int) $this->queue($advisory, $runtimes, $dedupeKey);
        }

        return $queued;
    }

    /**
     * @param  Collection<int, Runtime>  $runtimes  One team's runtimes of the technology.
     */
    private function queue(Advisory $advisory, Collection $runtimes, string $dedupeKey): bool
    {
        $team = $runtimes->first()->deployment->service->project->team;

        $payload = $this->withLocale(config('psm.alerts.locale'), fn (): array => $this->cards->preNotice($team, $advisory, $runtimes));

        return $this->queueChatNotification->handle($team, ChatNotificationKind::PreNotice, $dedupeKey, $payload) !== null;
    }
}
