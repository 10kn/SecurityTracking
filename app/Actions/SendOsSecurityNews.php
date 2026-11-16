<?php

namespace App\Actions;

use App\Enums\AdvisoryKind;
use App\Enums\ChatNotificationKind;
use App\Models\Advisory;
use App\Models\ChatNotification;
use App\Services\GoogleChatCards;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Traits\Localizable;

/**
 * Sends the operating system advisories that count to the admin's security news space (`psm.alerts.news_webhook`,
 * the admin space when it is not set), one card each, oldest first: those published within
 * `psm.notices.relevant_days` at a severity of `psm.alerts.immediate_severities` ("Gửi ngay khi mức độ" on the system
 * settings screen), or with a CVE id CISA KEV lists, except the history a feed listed on its first poll. Each one is
 * sent once: a severity that rises later, e.g. once Ubuntu rated more of its CVE ids, sends it then. Nothing is queued
 * while neither space is configured.
 */
class SendOsSecurityNews
{
    use Localizable;

    public function __construct(
        private QueueChatNotification $queueChatNotification,
        private GoogleChatCards $cards,
    ) {}

    /**
     * @return int The number of messages queued.
     */
    public function handle(): int
    {
        if (ChatNotification::securityNewsWebhookUrl() === null) {
            return 0;
        }

        return Advisory::where('kind', AdvisoryKind::OsAdvisory)
            ->where('is_backfill', false)
            ->whereNull('news_notification_id')
            ->where('published_at', '>=', now()->subDays(config('psm.notices.relevant_days')))
            ->where(fn (Builder $query) => $query->whereIn('severity', config('psm.alerts.immediate_severities'))->orWhereNotNull('known_exploited'))
            ->orderBy('published_at')
            ->orderBy('id')
            ->get()
            ->sum(fn (Advisory $advisory): int => (int) $this->send($advisory));
    }

    private function send(Advisory $advisory): bool
    {
        return DB::transaction(function () use ($advisory): bool {
            $advisory = Advisory::whereKey($advisory->id)->whereNull('news_notification_id')->lockForUpdate()->first();

            if ($advisory === null) {
                return false;
            }

            $payload = $this->withLocale(config('psm.alerts.locale'), fn (): array => $this->cards->osAdvisory($advisory));
            $dedupeKey = "os-news:{$advisory->id}";
            $notification = $this->queueChatNotification->handle(null, ChatNotificationKind::SecurityNews, $dedupeKey, $payload);

            Advisory::whereKey($advisory->id)->toBase()->update(['news_notification_id' => ($notification ?? ChatNotification::firstWhere('dedupe_key', $dedupeKey))?->id]);

            return $notification !== null;
        });
    }
}
