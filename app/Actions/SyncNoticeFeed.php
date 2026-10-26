<?php

namespace App\Actions;

use App\Enums\AdvisoryKind;
use App\Enums\Severity;
use App\Enums\SeverityBasis;
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
 *
 * Storing and classifying are two steps, so a caller can send what the vendor's words and the CVSS score already
 * make Critical or High before waiting for the classifier (see WatchVendorNotices).
 */
class SyncNoticeFeed
{
    public function __construct(private NoticeClassifier $classifier, private AssignNoticeToIncident $assignNoticeToIncident) {}

    /**
     * Polls and classifies in one go.
     *
     * @return list<Advisory> The pre-notices the feed lists now. `wasRecentlyCreated` and `wasChanged()` tell which
     *                        ones are new or were edited by the vendor.
     *
     * @throws Throwable The source error, after it was recorded on the heartbeat.
     */
    public function handle(Technology $technology): array
    {
        return $this->classify($technology, $this->poll($technology));
    }

    /**
     * Stores the entries the feed lists now with what can be read from them without the classifier, keeping the
     * previous classification. The incidents of the technology are locked first (see AssignNoticeToIncident), as
     * merging and assigning do, so they never wait on each other in opposite order.
     *
     * @return array<string, array{entry: NoticeEntry, advisory: Advisory}> Keyed by external id.
     *
     * @throws Throwable The source error, after it was recorded on the heartbeat.
     */
    public function poll(Technology $technology): array
    {
        $feed = $this->feed($technology);

        $heartbeat = SourceHeartbeat::updateOrCreate(['source' => $technology->noticeSourceKey()], [
            'name' => __('Tin bảo mật :source', ['source' => $technology->releaseSourceLabel()]),
            'threshold_minutes' => config('psm.notices.stale_after_minutes'),
        ]);
        $isFirstPoll = $heartbeat->last_success_at === null;

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

        $polled = DB::transaction(function () use ($technology, $relevant, $isFirstPoll): array {
            $this->assignNoticeToIncident->lock($technology);

            $stored = Advisory::where('source', $technology->noticeSourceKey())->whereIn('external_id', $relevant->keys())->get()->keyBy('external_id');

            return $relevant
                ->map(fn (NoticeEntry $entry, string $externalId): array => [
                    'entry' => $entry,
                    'advisory' => $this->store($technology, $entry, $stored->get($externalId), null, $isFirstPoll),
                ])
                ->all();
        });

        $heartbeat->update(['last_success_at' => now(), 'last_error' => null]);

        return $polled;
    }

    /**
     * Has the classifier read the polled entries that need it, and stores what it read.
     *
     * @param  array<string, array{entry: NoticeEntry, advisory: Advisory}>  $polled  What poll() returned.
     * @return list<Advisory>
     */
    public function classify(Technology $technology, array $polled): array
    {
        return collect($polled)
            ->map(function (array $item) use ($technology): Advisory {
                $classification = $this->classification($technology, $item['entry'], $item['advisory']);

                if ($classification === null) {
                    return $item['advisory'];
                }

                $advisory = $this->store($technology, $item['entry'], $item['advisory'], $classification, false);
                $this->assignNoticeToIncident->suggestFromClassifier($advisory, $classification->relatedIncidentId);

                return $advisory;
            })
            ->values()
            ->all();
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
     * Entries are classified once, and again when the vendor edits them, along with the recent incidents of the
     * technology the entry may be about. Entries older than `psm.notices.relevant_days` are not: they no longer alert
     * anything. A classifier error is reported and the entry is tried again on the next
     * poll; `classification_failed_at` keeps when the errors started, for psm:check-sources. A dismissal was made on
     * the earlier text, so it no longer holds for the new one.
     */
    private function classification(Technology $technology, NoticeEntry $entry, Advisory $advisory): ?NoticeClassification
    {
        if (! $this->classifier->isEnabled()
            || ! $this->isFresh($entry)
            || $advisory->content_hash === $this->contentHash($entry)) {
            return null;
        }

        try {
            return $this->classifier->classify($technology, $entry, $this->assignNoticeToIncident->recentIncidents($technology, $advisory->incident_id));
        } catch (Throwable $exception) {
            report($exception);

            $advisory->update([
                'classification_failed_at' => $advisory->classification_failed_at ?? now(),
                'is_dismissed' => false,
            ]);

            return null;
        }
    }

    /**
     * The severity is the highest of the vendor's words, the CVSS score the entry gives and the classifier, which can
     * dismiss an entry as not security news only when neither of the others found a severity in it. Without a new
     * classification, the previous one is kept.
     *
     * The pre-notice is put in its incident when it is new, and checked again for other incidents when what ties it to
     * them changes (see AssignNoticeToIncident).
     *
     * Entries a feed already lists on its first successful poll are history and are never sent, except a critical one
     * that is still fresh: a new install must not miss an ongoing critical issue. An entry of the history that turns
     * critical later, e.g. once classified, is sent too.
     */
    private function store(Technology $technology, NoticeEntry $entry, ?Advisory $stored, ?NoticeClassification $classification, bool $isFirstPoll): Advisory
    {
        $advisory = $stored ?? new Advisory([
            'source' => $technology->noticeSourceKey(),
            'external_id' => $this->externalId($entry),
        ]);

        if ($classification !== null) {
            $advisory->fill([
                'content_hash' => $this->contentHash($entry),
                'classified_by' => $this->classifier->name(),
                'classified_severity' => $classification->severity,
                'classification_failed_at' => null,
                'is_dismissed' => ! $classification->isSecurityNews,
                'planned_release_at' => $classification->plannedReleaseAt,
                'affected_branches' => $classification->affectedBranches,
                'unaffected_branches' => $classification->unaffectedBranches,
                'localized_summary' => $classification->summary,
            ]);
        }

        $cvssScore = $entry->cvssScore();
        $ratings = [
            SeverityBasis::Vendor->value => $entry->severity(),
            SeverityBasis::Cvss->value => $cvssScore === null ? Severity::Unknown : Severity::fromCvss($cvssScore),
            SeverityBasis::Classifier->value => $advisory->classified_severity ?? Severity::Unknown,
        ];
        $basis = collect($ratings)
            ->reject(fn (Severity $severity): bool => $severity === Severity::Unknown)
            ->sortByDesc(fn (Severity $severity): int => $severity->rank())
            ->keys()
            ->first();
        $severity = $basis === null ? Severity::Unknown : $ratings[$basis];

        $advisory->fill([
            'kind' => AdvisoryKind::PreNotice,
            'aliases' => $entry->referenceIds(),
            'title' => Str::limit($entry->title, 250),
            'summary' => $entry->summary(config('psm.notices.summary_length')),
            'severity' => $severity,
            'severity_basis' => $basis,
            'is_dismissed' => $advisory->is_dismissed && $ratings[SeverityBasis::Vendor->value] === Severity::Unknown && $ratings[SeverityBasis::Cvss->value] === Severity::Unknown,
            'cvss_score' => $cvssScore,
            'technology' => $technology,
            'url' => $entry->url !== null && strlen($entry->url) <= 255 ? $entry->url : null,
            'links' => $entry->links,
            'published_at' => $entry->publishedAt,
        ]);

        $isOngoingCritical = $severity === Severity::Critical && $this->isFresh($entry);

        if (! $advisory->exists) {
            $advisory->is_backfill = $isFirstPoll && ! $isOngoingCritical;
        } elseif ($isOngoingCritical) {
            $advisory->is_backfill = false;
        }

        $advisory->save();

        if ($advisory->incident_id === null || $advisory->wasChanged(['aliases', 'links', 'published_at', 'planned_release_at', 'is_dismissed'])) {
            $this->assignNoticeToIncident->handle($advisory);
        }

        return $advisory;
    }

    /**
     * Published within `psm.notices.relevant_days`: older news no longer alerts anything.
     */
    private function isFresh(NoticeEntry $entry): bool
    {
        return $entry->publishedAt !== null && ! $entry->publishedAt->isBefore(now()->subDays(config('psm.notices.relevant_days')));
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
