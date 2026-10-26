<?php

namespace App\Actions;

use App\Enums\AdvisoryKind;
use App\Enums\ChatNotificationKind;
use App\Enums\Severity;
use App\Models\Advisory;
use App\Models\ChatNotification;
use App\Models\Runtime;
use App\Services\AlertPolicy;
use App\Services\GoogleChatCards;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\Localizable;

/**
 * Sends "Cảnh báo chuẩn bị" (handover 5.2): every team that runs the technology of a pre-notice gets one card per
 * pre-notice, listing its services on that technology. Only pre-notices published within `psm.notices.relevant_days`
 * are sent, and never those a feed listed on its first poll (see SyncNoticeFeed). A team that starts running the
 * technology later gets the card on a later run while the pre-notice is still within that window. Running it again
 * sends nothing new.
 *
 * Medium and Low pre-notices wait for the daily digest (see AlertPolicy), and those the classifier dismissed as not
 * security news are not sent at all. Pre-notices only notify: they never open or escalate findings, since the vendor
 * rarely says which branches are affected.
 */
class SendPreNoticeAlerts
{
    use Localizable;

    public function __construct(private AlertPolicy $policy, private QueueChatNotification $queueChatNotification, private GoogleChatCards $cards) {}

    /**
     * @param  bool  $holdUnclassified  Leaves out pre-notices below Critical the classifier has not read yet: they wait
     *                                  for it, as it may raise them, while Critical ones go out right away.
     * @return int The number of cards queued.
     */
    public function handle(bool $holdUnclassified = false): int
    {
        return Advisory::where('kind', AdvisoryKind::PreNotice)
            ->whereNotNull('technology')
            ->where('is_dismissed', false)
            ->where('is_backfill', false)
            ->where('published_at', '>=', now()->subDays(config('psm.notices.relevant_days')))
            ->orderBy('published_at')
            ->get()
            ->filter(fn (Advisory $advisory): bool => $this->policy->sendsPreNoticeRightAway($advisory))
            ->reject(fn (Advisory $advisory): bool => $holdUnclassified && $advisory->severity !== Severity::Critical && $advisory->classified_by === null)
            ->sum(fn (Advisory $advisory): int => $this->send($advisory));
    }

    /**
     * A team hears about a pre-notice once at each severity: again only when the severity rises, e.g. from unknown
     * to Critical once classified. The severity is part of the dedupe key; for keys that predate it, the severity is
     * read from the card's title ("[High] …", no tag when unknown).
     */
    private function send(Advisory $advisory): int
    {
        $runtimesByTeam = Runtime::where('technology', $advisory->technology)
            ->whereHas('deployment', fn ($query) => $query->where('is_current', true))
            ->with('deployment.service.project.team')
            ->get()
            ->groupBy(fn (Runtime $runtime): int => $runtime->deployment->service->project->team_id);

        $queued = 0;

        foreach ($runtimesByTeam as $teamId => $runtimes) {
            $notifiedSeverity = $this->notifiedSeverity($advisory, $teamId);

            if ($notifiedSeverity !== null && $notifiedSeverity->rank() >= $advisory->severity->rank()) {
                continue;
            }

            $queued += (int) $this->queue($advisory, $runtimes, "pre_notice:{$advisory->id}:{$teamId}:{$advisory->severity->value}", $notifiedSeverity);
        }

        return $queued;
    }

    private function notifiedSeverity(Advisory $advisory, int $teamId): ?Severity
    {
        $prefix = "pre_notice:{$advisory->id}:{$teamId}";

        return ChatNotification::where('dedupe_key', $prefix)
            ->orWhere('dedupe_key', 'like', "{$prefix}:%")
            ->get()
            ->map(fn (ChatNotification $notification): Severity => $notification->dedupe_key === $prefix
                ? $this->severityOfTitle((string) ($notification->payload['text'] ?? ''))
                : Severity::tryFrom(Str::after($notification->dedupe_key, "{$prefix}:")) ?? Severity::Unknown)
            ->sortByDesc(fn (Severity $severity): int => $severity->rank())
            ->first();
    }

    private function severityOfTitle(string $title): Severity
    {
        foreach ([Severity::Critical, Severity::High, Severity::Medium, Severity::Low] as $severity) {
            if (str_starts_with($title, "[{$severity->getLabel()}]")) {
                return $severity;
            }
        }

        return Severity::Unknown;
    }

    /**
     * @param  Collection<int, Runtime>  $runtimes  One team's runtimes of the technology.
     */
    private function queue(Advisory $advisory, Collection $runtimes, string $dedupeKey, ?Severity $previousSeverity): bool
    {
        $team = $runtimes->first()->deployment->service->project->team;

        $payload = $this->withLocale(config('psm.alerts.locale'), fn (): array => $this->cards->preNotice($team, $advisory, $runtimes, $previousSeverity));

        return $this->queueChatNotification->handle($team, ChatNotificationKind::PreNotice, $dedupeKey, $payload) !== null;
    }
}
