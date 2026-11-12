<?php

namespace App\Actions;

use App\Enums\ChatNotificationKind;
use App\Enums\Technology;
use App\Models\Advisory;
use App\Models\ChatNotification;
use App\Models\Incident;
use App\Models\Runtime;
use App\Services\GoogleChatCards;
use App\Services\Notices\NoticeClassifier;
use App\Support\IncidentLock;
use App\Support\Version;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Traits\Localizable;

/**
 * Sends every vendor security news item that counts to the admin's security news space (`psm.alerts.news_webhook`,
 * the admin space when it is not set), whether or not a team runs its technology: the pre-notices
 * Advisory::alertable() keeps, so neither the history a feed lists on its first poll nor what the classifier
 * dismissed. Each one is sent once, oldest first, in one Google Chat thread per incident; a vendor editing it sends
 * nothing new. A pre-notice of an incident merged into another goes to the thread of the one it was merged into.
 *
 * The card names the fixes the incident has (see Incident::confirmedPatches()) and records them on the incident
 * (`news_patches`). A fix confirmed later, by a vendor editing a pre-notice it sent already (Node.js adds the fixed
 * versions to its pre-announcement once the releases are out) or once the catalog lists the version, is told as a
 * "Đã có bản vá" reply in the incident's thread, once per version. An incident whose news went out before fixes were
 * recorded records the ones it has without telling them.
 *
 * Like the team alerts, a pre-notice waits for the classifier, which may rate or dismiss it, unless its CVSS score or
 * a KEV listing makes it Critical. One it never read is dismissed until it does (see SyncNoticeFeed). Nothing is queued while neither space is
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

        $news = Advisory::alertable()
            ->whereNotNull('incident_id')
            ->whereNull('news_notification_id')
            ->orderBy('published_at')
            ->orderBy('id')
            ->get()
            ->reject(fn (Advisory $notice): bool => $this->isAwaitingClassifier($notice))
            ->sum(fn (Advisory $notice): int => (int) $this->send($notice));

        $patches = Incident::current()
            ->whereHas('notices', fn ($query) => $query->alertable()->whereNotNull('news_notification_id'))
            ->orderBy('id')
            ->get()
            ->sum(fn (Incident $incident): int => (int) $this->sendPatches($incident));

        return $news + $patches;
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
            IncidentLock::take([$notice->technology]);

            $notice = Advisory::whereKey($notice->id)->whereNull('news_notification_id')->lockForUpdate()->first();
            $incident = $notice?->incident()->lockForUpdate()->first();

            if ($incident === null) {
                return false;
            }

            $others = $incident->notices()->alertable()->whereKeyNot($notice->id)->orderBy('published_at')->orderBy('id')->get();
            $patches = $this->patchesOf($incident->confirmedPatches());

            $payload = $this->withLocale(config('psm.alerts.locale'), fn (): array => $this->cards->securityNews($incident, $notice, $others, $this->teamsRunning($incident->technology), $patches));
            $dedupeKey = "news:{$notice->id}";
            $notification = $this->queueChatNotification->handle(null, ChatNotificationKind::SecurityNews, $dedupeKey, $payload, attributes: ['thread_key' => self::threadKeyFor($incident)]);

            Advisory::whereKey($notice->id)->toBase()->update(['news_notification_id' => ($notification ?? ChatNotification::firstWhere('dedupe_key', $dedupeKey))?->id]);

            if ($notification !== null) {
                $incident->update(['news_patches' => array_replace($incident->news_patches ?? [], $patches)]);
            }

            return $notification !== null;
        });
    }

    /**
     * Replies "Đã có bản vá" in the incident's thread for the fixes it has that the news space was not told about: a
     * branch it was told no fix for, or a newer version of one. Each reply is queued once per incident and versions.
     */
    private function sendPatches(Incident $incident): bool
    {
        return DB::transaction(function () use ($incident): bool {
            IncidentLock::take([$incident->technology]);

            $incident = Incident::current()->whereKey($incident->id)->lockForUpdate()->first();

            if ($incident === null) {
                return false;
            }

            $confirmed = $incident->confirmedPatches();
            $patches = $this->patchesOf($confirmed);
            $told = $incident->news_patches;

            if ($told === null) {
                $incident->update(['news_patches' => $patches]);

                return false;
            }

            $news = array_filter($patches, fn (string $version, string $branch): bool => ! isset($told[$branch]) || Version::isNewer($version, $told[$branch]), ARRAY_FILTER_USE_BOTH);

            if ($news === []) {
                return false;
            }

            $notice = collect($confirmed)->only(array_keys($news))->pluck('notice')->sortByDesc(fn (Advisory $notice): int => $notice->id)->first();
            $payload = $this->withLocale(config('psm.alerts.locale'), fn (): array => $this->cards->securityNewsPatches($incident, $notice, $news, array_replace($told, $patches), $this->teamsRunning($incident->technology)));
            $dedupeKey = "news-patch:{$incident->id}:".hash('sha256', (string) json_encode($news));

            $this->queueChatNotification->handle(null, ChatNotificationKind::SecurityNews, $dedupeKey, $payload, attributes: ['thread_key' => self::threadKeyFor($incident)]);
            $incident->update(['news_patches' => array_replace($told, $patches)]);

            return true;
        });
    }

    /**
     * Branch => fixed version, by branch.
     *
     * @param  array<string, array{version: string, notice: Advisory}>  $confirmed  See Incident::confirmedPatches().
     * @return array<string, string>
     */
    private function patchesOf(array $confirmed): array
    {
        $patches = array_map(fn (array $patch): string => $patch['version'], $confirmed);
        ksort($patches, SORT_NATURAL);

        return $patches;
    }

    /**
     * The names of the teams whose monitored services run the technology, by name.
     *
     * @return list<string>
     */
    private function teamsRunning(Technology $technology): array
    {
        return Runtime::where('technology', $technology)
            ->monitored()
            ->with('deployment.service.project.team')
            ->get()
            ->map(fn (Runtime $runtime): string => $runtime->deployment->service->project->team->name)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
