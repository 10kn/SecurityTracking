<?php

namespace App\Actions;

use App\Enums\ChatNotificationKind;
use App\Models\Advisory;
use App\Models\ChatNotification;
use App\Models\Incident;
use App\Models\Runtime;
use App\Services\GoogleChatCards;
use App\Services\Notices\NoticeClassifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Traits\Localizable;

/**
 * Sends every vendor security news item that counts to the admin's security news space (`psm.alerts.news_webhook`,
 * the admin space when it is not set), whether or not a team runs its technology: the pre-notices
 * Advisory::alertable() keeps, so neither the history a feed lists on its first poll nor what the classifier
 * dismissed. Each one is sent once, oldest first, in one Google Chat thread per incident; a vendor editing it sends
 * nothing new. A pre-notice of an incident merged into another goes to the thread of the one it was merged into.
 *
 * Like the team alerts, a pre-notice waits for the classifier, which may rate or dismiss it, unless its CVSS score or
 * a KEV listing makes it Critical, or the classifier is off or failed on it. Nothing is queued while neither space is
 * configured: the news of the last `psm.notices.relevant_days` goes out once one is.
 */
class SendSecurityNews
{
    use Localizable;

    public function __construct(
        private AssignNoticeToIncident $assignNoticeToIncident,
        private QueueChatNotification $queueChatNotification,
        private GoogleChatCards $cards,
        private NoticeClassifier $classifier,
    ) {}

    /**
     * @return int The number of messages queued.
     */
    public function handle(): int
    {
        if (ChatNotification::securityNewsWebhookUrl() === null) {
            return 0;
        }

        $this->assignNoticeToIncident->assignUnassigned();

        return Advisory::alertable()
            ->whereNotNull('incident_id')
            ->whereNull('news_notification_id')
            ->orderBy('published_at')
            ->orderBy('id')
            ->get()
            ->reject(fn (Advisory $notice): bool => $this->isAwaitingClassifier($notice))
            ->sum(fn (Advisory $notice): int => (int) $this->send($notice));
    }

    /**
     * A thread key no other installation posting to the same space uses (see IncidentTeam::threadKeyFor()).
     */
    public static function threadKeyFor(Incident $incident): string
    {
        $namespace = substr(hash('sha256', 'psm-thread:'.config('app.key')), 0, 12);

        return "psm-{$namespace}-news-incident-{$incident->id}";
    }

    private function isAwaitingClassifier(Advisory $notice): bool
    {
        return $this->classifier->isEnabled() && $notice->classified_by === null && $notice->classification_failed_at === null && ! $notice->isCriticalBeyondWords();
    }

    private function send(Advisory $notice): bool
    {
        return DB::transaction(function () use ($notice): bool {
            $notice = Advisory::whereKey($notice->id)->whereNull('news_notification_id')->lockForUpdate()->first();
            $incident = $notice?->incident;

            if ($incident === null) {
                return false;
            }

            $others = $incident->notices()->alertable()->whereKeyNot($notice->id)->orderBy('published_at')->orderBy('id')->get();
            $teams = Runtime::where('technology', $notice->technology)
                ->monitored()
                ->with('deployment.service.project.team')
                ->get()
                ->map(fn (Runtime $runtime): string => $runtime->deployment->service->project->team->name)
                ->unique()
                ->sort()
                ->values()
                ->all();

            $payload = $this->withLocale(config('psm.alerts.locale'), fn (): array => $this->cards->securityNews($incident, $notice, $others, $teams));
            $dedupeKey = "news:{$notice->id}";
            $notification = $this->queueChatNotification->handle(null, ChatNotificationKind::SecurityNews, $dedupeKey, $payload, attributes: ['thread_key' => self::threadKeyFor($incident)]);

            Advisory::whereKey($notice->id)->toBase()->update(['news_notification_id' => ($notification ?? ChatNotification::firstWhere('dedupe_key', $dedupeKey))?->id]);

            return $notification !== null;
        });
    }
}
