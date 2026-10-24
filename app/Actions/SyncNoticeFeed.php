<?php

namespace App\Actions;

use App\Enums\AdvisoryKind;
use App\Enums\ReleaseSource;
use App\Models\Advisory;
use App\Models\SourceHeartbeat;
use App\Models\Technology;
use App\Services\Notices\NoticeEntry;
use App\Services\Notices\NoticeFeed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Polls the security news feed of a technology's release source and stores the entries that matter as pre-notice
 * advisories, keyed by feed and entry id so polling again changes nothing. Every attempt is recorded on the feed's
 * source heartbeat.
 */
class SyncNoticeFeed
{
    /**
     * @return list<Advisory> The pre-notices the feed lists now. `wasRecentlyCreated` and `wasChanged()` tell which
     *                        ones are new or were edited by the vendor.
     *
     * @throws Throwable The source error, after it was recorded on the heartbeat.
     */
    public function handle(Technology $technology): array
    {
        $source = $technology->release_source;
        $feed = $this->feed($source);

        if ($feed === null) {
            return [];
        }

        $heartbeat = SourceHeartbeat::updateOrCreate(['source' => $source->noticeSourceKey()], [
            'name' => __('Tin bảo mật :source', ['source' => $source->getLabel()]),
            'threshold_minutes' => config('psm.notices.stale_after_minutes'),
        ]);

        try {
            $entries = $feed->entries();
        } catch (Throwable $exception) {
            $heartbeat->update([
                'last_failure_at' => now(),
                'last_error' => Str::limit(class_basename($exception).': '.$exception->getMessage(), 500),
            ]);

            throw $exception;
        }

        $relevant = collect($entries)
            ->filter(fn (NoticeEntry $entry): bool => $this->isRelevant($feed, $entry))
            ->unique(fn (NoticeEntry $entry): string => $this->externalId($entry));

        $advisories = DB::transaction(fn (): array => $relevant
            ->map(fn (NoticeEntry $entry): Advisory => $this->store($technology, $entry))
            ->values()
            ->all());

        $heartbeat->update(['last_success_at' => now(), 'last_error' => null]);

        return $advisories;
    }

    private function feed(ReleaseSource $source): ?NoticeFeed
    {
        $feedClass = $source->noticeFeed();

        return $feedClass === null ? null : app($feedClass);
    }

    /**
     * Release announcements are left to the catalog sync, which alerts on each security release with the version to
     * upgrade to; everything else needs a keyword unless the feed only publishes security news.
     */
    private function isRelevant(NoticeFeed $feed, NoticeEntry $entry): bool
    {
        foreach (config('psm.notices.ignored_title_patterns') as $pattern) {
            if (preg_match($pattern, $entry->title) === 1) {
                return false;
            }
        }

        return $feed->isSecurityOnly() || $entry->mentionsAny(config('psm.notices.keywords'));
    }

    private function store(Technology $technology, NoticeEntry $entry): Advisory
    {
        return Advisory::updateOrCreate(
            ['source' => $technology->release_source->noticeSourceKey(), 'external_id' => $this->externalId($entry)],
            [
                'kind' => AdvisoryKind::PreNotice,
                'aliases' => $entry->cveIds(),
                'title' => Str::limit($entry->title, 250),
                'summary' => $entry->summary(config('psm.notices.summary_length')),
                'severity' => $entry->severity(),
                'cvss_score' => $entry->cvssScore(),
                'technology_id' => $technology->id,
                'url' => $entry->url !== null && strlen($entry->url) <= 255 ? $entry->url : null,
                'published_at' => $entry->publishedAt,
            ],
        );
    }

    /**
     * Ids longer than the column are hashed.
     */
    private function externalId(NoticeEntry $entry): string
    {
        return strlen($entry->externalId) <= 255 ? $entry->externalId : 'sha256:'.hash('sha256', $entry->externalId);
    }
}
