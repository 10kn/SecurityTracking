<?php

namespace App\Actions;

use App\Enums\AdvisoryKind;
use App\Enums\Severity;
use App\Enums\SeverityBasis;
use App\Enums\Technology;
use App\Models\Advisory;
use App\Models\Incident;
use App\Models\IncidentTeamNotice;
use App\Models\SourceHeartbeat;
use App\Services\Kev\KnownExploitation;
use App\Services\Notices\NoticeClassification;
use App\Services\Notices\NoticeClassifier;
use App\Services\Notices\NoticeEntry;
use App\Services\Notices\NoticeFeed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Polls the news feed of a technology and stores every recent entry as a pre-notice advisory, keyed by feed and entry id so
 * polling again changes nothing. Every attempt is recorded on the feed's source heartbeat. News has no fixed
 * structure, so only the notice classifier reads its words: whether it is security news, how severe, which versions
 * fix it and whether it is exploited (see `psm.notices`). Without the classifier, only what has a fixed format counts:
 * CVE and GHSA ids, a CVSS score and a CISA KEV listing.
 *
 * Storing and classifying are two steps, so a caller can send what the CVSS score or a KEV listing already makes
 * Critical before waiting for the classifier (see WatchVendorNotices).
 */
class SyncNoticeFeed
{
    public function __construct(
        private NoticeClassifier $classifier,
        private AssignNoticeToIncident $assignNoticeToIncident,
        private MatchKnownExploited $matchKnownExploited,
        private KnownExploitation $knownExploitation,
        private ReportClassifierOutage $reportClassifierOutage,
    ) {}

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
     * previous classification. An entry published before `psm.notices.collect_days` is skipped unless it is already
     * stored: the feed's older history is about versions no project runs any more. The incidents of the technology are locked first (see AssignNoticeToIncident), as
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

        $listed = collect($entries)
            ->unique(fn (NoticeEntry $entry): string => $this->externalId($entry))
            ->keyBy(fn (NoticeEntry $entry): string => $this->externalId($entry));

        $polled = DB::transaction(function () use ($technology, $listed, $isFirstPoll): array {
            $this->assignNoticeToIncident->lock($technology);

            $stored = Advisory::where('source', $technology->noticeSourceKey())->whereIn('external_id', $listed->keys())->get()->keyBy('external_id');

            return $listed
                ->filter(fn (NoticeEntry $entry, string $externalId): bool => $this->isWithinCollectWindow($entry) || $stored->has($externalId))
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
     * Entries are classified once, and again when the vendor edits them, along with the recent incidents of the
     * technology the entry may be about. Entries older than `psm.notices.relevant_days`, e.g. the history a feed lists
     * on its first poll, are classified once too while they are within `psm.notices.collect_days`, as nothing else
     * tells whether they are security news, but their edits are not. Older ones are never read. Both hold
     * unless the entry belongs to an incident a team was sent a card about: a newly confirmed branch or exploitation
     * still reaches that team (see SendPreNoticeAlerts). A classifier error is reported and the entry is tried again
     * on the next poll, and the admin is told once per outage (see ReportClassifierOutage); `classification_failed_at`
     * keeps when the errors started, for psm:check-sources. Meanwhile what it read before stands: an entry it never
     * read waits for it.
     *
     * An entry an earlier revision of the classifier read (see NoticeClassifier::revision(); none stored is the first)
     * is read again while it is within `psm.notices.collect_days`, even when its text did not change: what the
     * classifier reads changed.
     */
    private function classification(Technology $technology, NoticeEntry $entry, Advisory $advisory): ?NoticeClassification
    {
        $isReadByEarlierRevision = $advisory->content_hash !== null
            && ($advisory->classifier_revision ?? 1) < $this->classifier->revision()
            && $this->isWithinCollectWindow($entry);

        if (! $this->classifier->isEnabled()
            || (! $isReadByEarlierRevision
                && ($advisory->content_hash === $this->contentHash($entry)
                    || (! $this->isFresh($entry) && ($advisory->content_hash !== null || ! $this->isWithinCollectWindow($entry)) && ! $this->isOfToldIncident($advisory))))) {
            return null;
        }

        try {
            $classification = $this->classifier->classify($technology, $entry, $this->assignNoticeToIncident->recentIncidents($technology, $advisory->incident_id));
        } catch (Throwable $exception) {
            report($exception);
            $this->reportClassifierOutage->failed($exception);

            $advisory->update(['classification_failed_at' => $advisory->classification_failed_at ?? now()]);

            return null;
        }

        $this->reportClassifierOutage->recovered();

        return $classification;
    }

    /**
     * The severity comes from the first that rates the entry of CISA KEV listing one of its CVE ids (see
     * MatchKnownExploited), the CVSS score the entry gives and the classifier, even when a later one rates it higher
     * (see SeverityBasis::precedence()). A severity that falls once the vendor adds a score tells no team again (see
     * SendPreNoticeAlerts). An entry is security news when the classifier says so, or its CVSS score or a KEV listing
     * does: one the classifier never read is dismissed until it does, so it waits for it. Without a new
     * classification, the previous one is kept, the versions it read to update to included. The KEV evidence is kept
     * apart from what the classifier read in the entry (`is_exploited`), so polling again never drops it.
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
                'classifier_revision' => $this->classifier->revision(),
                'classified_severity' => $classification->severity,
                'classification_failed_at' => null,
                'is_dismissed' => ! $classification->isSecurityNews,
                'planned_release_at' => $classification->plannedReleaseAt,
                'affected_branches' => $classification->affectedBranches,
                'unaffected_branches' => $classification->unaffectedBranches,
                'patch_versions' => $classification->patchVersions ?: null,
                'localized_summary' => $classification->summary,
            ]);
        }

        $advisory->aliases = $entry->referenceIds();
        $this->matchKnownExploited->fill($advisory);

        $cvssScore = $entry->cvssScore();
        $isDismissed = $advisory->classified_by === null || $advisory->is_dismissed;
        $ratings = collect([
            SeverityBasis::Kev->value => $advisory->isKnownExploited() ? $this->knownExploitation->severity() : Severity::Unknown,
            SeverityBasis::Cvss->value => $cvssScore === null ? Severity::Unknown : Severity::fromCvss($cvssScore),
            SeverityBasis::Classifier->value => $advisory->classified_severity ?? Severity::Unknown,
        ])
            ->reject(fn (Severity $severity): bool => $severity === Severity::Unknown)
            ->sortByDesc(fn (Severity $severity, string $basis): int => SeverityBasis::from($basis)->precedence());
        $basis = $ratings->keys()->first();
        $severity = $ratings->first() ?? Severity::Unknown;
        $withoutKev = $ratings->except(SeverityBasis::Kev->value)->first() ?? Severity::Unknown;

        $advisory->fill([
            'kind' => AdvisoryKind::PreNotice,
            'title' => Str::limit($entry->title, 250),
            'summary' => $entry->summary(config('psm.notices.summary_length')),
            'severity' => $severity,
            'severity_basis' => $basis,
            'severity_before_kev' => $basis === SeverityBasis::Kev->value ? $withoutKev : null,
            'is_dismissed' => $isDismissed && ! $ratings->has(SeverityBasis::Cvss->value) && ! $advisory->isKnownExploited(),
            'cvss_score' => $cvssScore,
            'technology' => $technology,
            'url' => $entry->url !== null && strlen($entry->url) <= 255 ? $entry->url : null,
            'links' => $entry->links,
            'published_at' => $entry->publishedAt,
            ...$this->exploitation($entry, $advisory, $classification),
            ...$this->platforms($entry, $advisory, $classification),
        ]);

        $isOngoingCritical = ($severity === Severity::Critical && $this->isFresh($entry)) || KnownExploitation::isFresh($advisory->known_exploited);

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
     * Whether the entry says its vulnerability is exploited: the classifier, with a sentence it quoted from the entry,
     * which only counts when the entry has it (see NoticeEntry::quotes()). Without a new classification, the sentence
     * the classifier quoted before still counts while the entry still has it.
     *
     * @return array{is_exploited: bool, exploited_basis: array{method: string, excerpt: string, content_hash: string}|null}
     */
    private function exploitation(NoticeEntry $entry, Advisory $advisory, ?NoticeClassification $classification): array
    {
        $quoted = $classification === null
            ? (($advisory->exploited_basis['method'] ?? null) === 'classifier' ? $advisory->exploited_basis['excerpt'] : null)
            : ($classification->activelyExploited === true ? $classification->exploitedEvidence : null);
        $basis = $quoted !== null && $entry->quotes($quoted) ? ['method' => 'classifier', 'excerpt' => $quoted] : null;

        return ['is_exploited' => $basis !== null, 'exploited_basis' => $basis === null ? null : [...$basis, 'content_hash' => $this->contentHash($entry)]];
    }

    /**
     * The operating systems the entry says it is limited to (see Incident::scopeFor()): the classifier's, with a
     * sentence it quoted from the entry, which only counts when the entry has it (see NoticeEntry::quotes()); without
     * it, the entry states no limit (null). Without a new classification, the limit read before still counts while the
     * entry still has its sentence.
     *
     * @return array{platforms: list<string>|null, platforms_evidence: string|null}
     */
    private function platforms(NoticeEntry $entry, Advisory $advisory, ?NoticeClassification $classification): array
    {
        [$platforms, $evidence] = $classification === null
            ? [$advisory->platforms ?? [], $advisory->platforms_evidence]
            : [$classification->platforms, $classification->platformsEvidence];
        $isQuoted = $platforms !== [] && is_string($evidence) && $entry->quotes($evidence);

        return ['platforms' => $isQuoted ? array_values($platforms) : null, 'platforms_evidence' => $isQuoted ? $evidence : null];
    }

    /**
     * A pre-notice of a current incident some team was sent a card about.
     */
    private function isOfToldIncident(Advisory $advisory): bool
    {
        return $advisory->incident_id !== null
            && Incident::current()->whereKey($advisory->incident_id)->exists()
            && IncidentTeamNotice::wasSentToAnyTeam(Advisory::where('incident_id', $advisory->incident_id)->pluck('id')->all());
    }

    /**
     * Published within `psm.notices.relevant_days`: older news no longer alerts anything new.
     */
    private function isFresh(NoticeEntry $entry): bool
    {
        return $entry->publishedAt !== null && ! $entry->publishedAt->isBefore(now()->subDays(config('psm.notices.relevant_days')));
    }

    /**
     * Published within `psm.notices.collect_days` of now, never fewer than `psm.notices.relevant_days`. An entry
     * without a date counts as recent: nothing says it is old.
     */
    private function isWithinCollectWindow(NoticeEntry $entry): bool
    {
        $days = max(config('psm.notices.collect_days'), config('psm.notices.relevant_days'));

        return $entry->publishedAt === null || ! $entry->publishedAt->isBefore(now()->subDays($days));
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
