<?php

namespace App\Actions;

use App\Enums\AdvisoryKind;
use App\Enums\Severity;
use App\Enums\Technology;
use App\Models\Advisory;
use App\Models\SourceHeartbeat;
use App\Services\Notices\NoticeClassification;
use App\Services\Notices\NoticeClassifier;
use App\Services\Notices\NoticeEntry;
use App\Services\Notices\NoticeFeed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Polls the security news feed of a technology and stores the entries that matter as pre-notice
 * advisories, keyed by feed and entry id so polling again changes nothing. Every attempt is recorded on the feed's
 * source heartbeat. When the notice classifier is configured, it reads each fresh entry (see `psm.notices`).
 */
class SyncNoticeFeed
{
    public function __construct(private NoticeClassifier $classifier) {}

    /**
     * @return list<Advisory> The pre-notices the feed lists now. `wasRecentlyCreated` and `wasChanged()` tell which
     *                        ones are new or were edited by the vendor.
     *
     * @throws Throwable The source error, after it was recorded on the heartbeat.
     */
    public function handle(Technology $technology): array
    {
        $feed = $this->feed($technology);

        $heartbeat = SourceHeartbeat::updateOrCreate(['source' => $technology->noticeSourceKey()], [
            'name' => __('Tin bảo mật :source', ['source' => $technology->releaseSourceLabel()]),
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
            ->unique(fn (NoticeEntry $entry): string => $this->externalId($entry))
            ->keyBy(fn (NoticeEntry $entry): string => $this->externalId($entry));

        $stored = Advisory::where('source', $technology->noticeSourceKey())->whereIn('external_id', $relevant->keys())->get()->keyBy('external_id');
        $classifications = $relevant->map(fn (NoticeEntry $entry, string $externalId): ?NoticeClassification => $this->classify($technology, $entry, $stored->get($externalId)));

        $advisories = DB::transaction(fn (): array => $relevant
            ->map(fn (NoticeEntry $entry, string $externalId): Advisory => $this->store($technology, $entry, $stored->get($externalId), $classifications->get($externalId)))
            ->values()
            ->all());

        $heartbeat->update(['last_success_at' => now(), 'last_error' => null]);

        return $advisories;
    }

    private function feed(Technology $technology): NoticeFeed
    {
        return app($technology->noticeFeed());
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

    /**
     * Entries are classified once, and again when the vendor edits them. Entries older than the escalation window
     * are not: they no longer alert or escalate anything. A classifier error is reported and the entry is tried
     * again on the next poll.
     */
    private function classify(Technology $technology, NoticeEntry $entry, ?Advisory $stored): ?NoticeClassification
    {
        if (! $this->classifier->isEnabled()
            || $entry->publishedAt === null
            || $entry->publishedAt->isBefore(now()->subDays(config('psm.notices.escalation_days')))
            || $stored?->content_hash === $this->contentHash($entry)) {
            return null;
        }

        try {
            return $this->classifier->classify($technology, $entry);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * The classifier can only raise the keyword severity, and can only dismiss an entry the keywords found no
     * severity in. Without a new classification, the previous one is kept.
     */
    private function store(Technology $technology, NoticeEntry $entry, ?Advisory $stored, ?NoticeClassification $classification): Advisory
    {
        $advisory = $stored ?? new Advisory([
            'source' => $technology->noticeSourceKey(),
            'external_id' => $this->externalId($entry),
        ]);
        $keywordSeverity = $entry->severity();

        if ($classification !== null) {
            $advisory->fill([
                'content_hash' => $this->contentHash($entry),
                'classified_by' => $this->classifier->name(),
                'classified_severity' => $classification->severity,
                'is_dismissed' => ! $classification->isSecurityNews,
                'planned_release_at' => $classification->plannedReleaseAt,
                'affected_branches' => $classification->affectedBranches,
                'localized_summary' => $classification->summary,
            ]);
        }

        $classifiedSeverity = $advisory->classified_severity ?? Severity::Unknown;

        $advisory->fill([
            'kind' => AdvisoryKind::PreNotice,
            'aliases' => $entry->cveIds(),
            'title' => Str::limit($entry->title, 250),
            'summary' => $entry->summary(config('psm.notices.summary_length')),
            'severity' => $classifiedSeverity->rank() > $keywordSeverity->rank() ? $classifiedSeverity : $keywordSeverity,
            'is_dismissed' => $advisory->is_dismissed && $keywordSeverity === Severity::Unknown,
            'cvss_score' => $entry->cvssScore(),
            'technology' => $technology,
            'url' => $entry->url !== null && strlen($entry->url) <= 255 ? $entry->url : null,
            'published_at' => $entry->publishedAt,
        ])->save();

        return $advisory;
    }

    private function contentHash(NoticeEntry $entry): string
    {
        return hash('sha256', "{$entry->title}\n{$entry->text}");
    }

    /**
     * Ids longer than the column are hashed.
     */
    private function externalId(NoticeEntry $entry): string
    {
        return strlen($entry->externalId) <= 255 ? $entry->externalId : 'sha256:'.hash('sha256', $entry->externalId);
    }
}
