<?php

namespace App\Actions;

use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\APITimeoutException;
use App\Enums\ChatNotificationKind;
use App\Models\SecurityNewsArticle;
use App\Models\SourceHeartbeat;
use App\Models\Team;
use App\Services\GoogleChatCards;
use App\Services\Notices\JpcertNewsClassifier;
use App\Services\Notices\JpcertNewsFeed;
use App\Services\SystemSettings;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\Localizable;
use RuntimeException;
use Throwable;

class SyncJpcertNews
{
    use Localizable;

    public const string SOURCE_PREFIX = 'jpcert:';

    private const int LOOKBACK_DAYS = 30;

    private const int BATCH_SIZE = 8;

    private const int MAX_ATTEMPTS = 3;

    public function __construct(
        private JpcertNewsFeed $feed,
        private JpcertNewsClassifier $classifier,
        private QueueChatNotification $queueChatNotification,
        private GoogleChatCards $cards,
        private SystemSettings $settings,
    ) {}

    /** @return array{classified: int, queued: int, failed: bool} */
    public function handle(): array
    {
        /** @var list<string> $tags */
        $tags = config('psm.jpcert.tags');
        if ($tags === []) {
            return ['classified' => 0, 'queued' => 0, 'failed' => false];
        }

        $activatedAt = $this->settings->jpcertActivatedAt();
        $today = CarbonImmutable::today(JpcertNewsFeed::TIMEZONE);
        $since = $today->subDays(self::LOOKBACK_DAYS);
        $failed = false;
        foreach ([JpcertNewsFeed::ALERTS => 'JPCERT/CC · 注意喚起', JpcertNewsFeed::FLASH => 'JPCERT/CC · CyberNewsFlash'] as $section => $name) {
            $heartbeat = $this->heartbeat($section, $name);
            try {
                $this->collect($section, $heartbeat, $since, $today, $activatedAt);
            } catch (Throwable $exception) {
                $this->fail($heartbeat, $exception);
                $failed = true;
            }
        }

        $classified = 0;
        $classification = $this->heartbeat('classification', 'JPCERT/CC · AI');
        try {
            $classified = $this->classify($tags, $since, $activatedAt);
            $classification->update(['last_success_at' => now(), 'last_error' => null]);
        } catch (Throwable $exception) {
            $this->fail($classification, $exception);
            $failed = true;
        }

        return ['classified' => $classified, 'queued' => $this->send($today, $activatedAt), 'failed' => $failed];
    }

    private function collect(string $section, SourceHeartbeat $heartbeat, CarbonImmutable $since, CarbonImmutable $today, ?CarbonInterface $activatedAt): void
    {
        $entries = $this->feed->entries($section, $since, $today);

        DB::transaction(function () use ($section, $heartbeat, $entries, $activatedAt): void {
            $source = SourceHeartbeat::whereKey($heartbeat->getKey())->lockForUpdate()->firstOrFail();
            $isFirstPoll = $source->last_success_at === null || ($activatedAt !== null && $source->last_success_at->lt($activatedAt));
            $activatedOn = $activatedAt?->avoidMutation()->setTimezone(JpcertNewsFeed::TIMEZONE)->toDateString();

            foreach ($entries as $entry) {
                $isBackfill = $isFirstPoll && ($activatedOn === null || $entry['listed_on'] <= $activatedOn);
                $article = SecurityNewsArticle::firstOrCreate(['url' => $entry['url']], [
                    'section' => $section,
                    'title' => $entry['title'],
                    'listed_on' => $entry['listed_on'],
                    'listed_seen_at' => now(),
                    'published_on' => $entry['listed_on'],
                    'is_backfill' => $isBackfill,
                ]);

                if ($article->accepted_at === null && $entry['listed_on'] > $article->listed_on->toDateString()) {
                    $article->update([
                        'listed_on' => $entry['listed_on'],
                        'listed_seen_at' => now(),
                        'is_backfill' => $isBackfill,
                        'classification_key' => null,
                        'attempt_key' => null,
                        'attempts' => 0,
                    ]);
                }
            }

            $source->update(['last_success_at' => now(), 'last_error' => null]);
        });
    }

    /** @param list<string> $tags */
    private function classify(array $tags, CarbonImmutable $since, ?CarbonInterface $activatedAt): int
    {
        if (blank(config('psm.notices.classifier.api_key'))) {
            throw new RuntimeException('JPCERT/CC needs the Anthropic classifier API key.');
        }

        $sortedTags = $tags;
        sort($sortedTags, SORT_STRING);
        $key = hash('sha256', json_encode([
            JpcertNewsClassifier::REVISION, config('psm.notices.classifier.model'), config('psm.notices.classifier.effort'), $sortedTags,
        ], JSON_THROW_ON_ERROR));

        $articles = SecurityNewsArticle::whereNull('accepted_at')
            ->where('listed_on', '>=', $since->toDateString())
            ->where(fn ($query) => $query->whereNull('classification_key')->orWhere('classification_key', '!=', $key))
            ->where(fn ($query) => $query->whereNull('attempt_key')->orWhere('attempt_key', '!=', $key)->orWhere('attempts', '<', self::MAX_ATTEMPTS))
            ->orderByRaw('last_attempt_at asc nulls first')
            ->latest('listed_on')
            ->orderBy('id')
            ->limit(self::BATCH_SIZE)
            ->get();

        $failure = null;
        $classified = 0;
        foreach ($articles as $article) {
            $article->update(['last_attempt_at' => now()]);

            try {
                $entry = $this->feed->article($article->section, $article->url, $article->title, $article->listed_on);
                $decision = $this->classifier->classify($entry, $tags);
            } catch (Throwable $exception) {
                if ($this->isProviderUnavailable($exception)) {
                    throw $exception;
                }

                $failure ??= $exception;
                $article->update([
                    'attempt_key' => $key,
                    'attempts' => ($article->attempt_key === $key ? $article->attempts : 0) + 1,
                    'last_error' => Str::limit($exception->getMessage(), 500),
                ]);
                report($exception);

                continue;
            }

            $article->update([
                'title' => $entry->title,
                'published_on' => ($entry->publishedAt ?? $article->listed_on)->toDateString(),
                'classification_key' => $key,
                'attempt_key' => null,
                'attempts' => 0,
                'last_error' => null,
                'is_backfill' => $article->is_backfill || $article->classification_key !== null
                    || ($activatedAt !== null && $article->listed_seen_at->lt($activatedAt)),
                ...($decision === null ? [] : [...$decision, 'accepted_at' => now(), 'cve_ids' => $entry->cveIds()]),
            ]);
            $classified++;
        }

        if ($failure !== null && $classified === 0) {
            throw $failure;
        }

        return $classified;
    }

    private function send(CarbonImmutable $today, ?CarbonInterface $activatedAt): int
    {
        if (! Str::startsWith((string) config('psm.alerts.admin_webhook'), Team::GOOGLE_CHAT_WEBHOOK_PREFIX)) {
            return 0;
        }

        return SecurityNewsArticle::whereNotNull('accepted_at')
            ->where('is_backfill', false)
            ->whereNull('notification_queued_at')
            ->where('listed_on', '>=', $today->subDay()->toDateString())
            ->where('accepted_at', '>=', now()->subDay())
            ->when($activatedAt !== null, fn ($query) => $query->where('listed_seen_at', '>=', $activatedAt))
            ->oldest('listed_on')->oldest('id')->get()
            ->sum(fn (SecurityNewsArticle $article): int => DB::transaction(function () use ($article): int {
                $key = "jpcert-news:{$article->id}";
                $payload = $this->withLocale(config('psm.alerts.locale'), fn (): array => $this->cards->jpcertNews($article));
                $notification = $this->queueChatNotification->handle(null, ChatNotificationKind::JpcertNews, $key, $payload);
                $article->update(['notification_queued_at' => now()]);

                return (int) ($notification !== null);
            }));
    }

    private function heartbeat(string $key, string $name): SourceHeartbeat
    {
        return SourceHeartbeat::firstOrCreate(['source' => self::SOURCE_PREFIX.$key], [
            'name' => $name,
            'threshold_minutes' => 120,
        ]);
    }

    private function isProviderUnavailable(Throwable $exception): bool
    {
        if ($exception instanceof APIConnectionException) {
            return ! $exception instanceof APITimeoutException;
        }

        return $exception instanceof APIStatusException
            && (in_array($exception->status, [401, 403, 404, 429], true) || $exception->status >= 500);
    }

    private function fail(SourceHeartbeat $heartbeat, Throwable $exception): void
    {
        $heartbeat->update([
            'last_failure_at' => now(),
            'last_error' => Str::limit(class_basename($exception).': '.$exception->getMessage(), 500),
        ]);
        report($exception);
    }
}
