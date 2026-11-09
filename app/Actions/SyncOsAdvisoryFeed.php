<?php

namespace App\Actions;

use App\Enums\AdvisoryKind;
use App\Enums\LinuxDistribution;
use App\Models\Advisory;
use App\Models\SourceHeartbeat;
use App\Services\Kev\KnownExploitation;
use App\Services\OsAdvisories\OsAdvisoryEntry;
use App\Services\OsAdvisories\OsReleaseSupport;
use Illuminate\Support\Str;
use Throwable;

/**
 * Polls the security advisories of an operating system and stores each as an `os_advisory`, keyed by feed and
 * advisory id, so polling again only applies what changed. Advisories are structured: no classifier reads them. Every
 * attempt is recorded on the feed's source heartbeat.
 *
 * Only advisories published within `psm.notices.collect_days` are stored, unless already stored. For Ubuntu and
 * Debian an advisory counts for the releases still in standard support only, and one that fixes none of them is not
 * stored (see OsReleaseSupport); one stored before keeps its last releases. The advisories a feed lists on its first
 * successful poll are history and never sent, except one with a CVE id CISA added to KEV recently.
 */
class SyncOsAdvisoryFeed
{
    public function __construct(
        private OsReleaseSupport $releaseSupport,
        private MatchKnownExploited $matchKnownExploited,
    ) {}

    /**
     * @return list<Advisory> The advisories the feed lists now that count; `wasRecentlyCreated` tells the new ones.
     *
     * @throws Throwable The source error, after it was recorded on the heartbeat.
     */
    public function handle(LinuxDistribution $os): array
    {
        $heartbeat = SourceHeartbeat::updateOrCreate(['source' => $os->sourceKey()], [
            'name' => __('Tin bảo mật :source', ['source' => $os->getLabel()]),
            'threshold_minutes' => config('psm.os_advisories.stale_after_minutes'),
        ]);
        $isFirstPoll = $heartbeat->last_success_at === null;

        try {
            $this->refreshReleases($os);
            $entries = $os->feed()->entries();
        } catch (Throwable $exception) {
            $heartbeat->update([
                'last_failure_at' => now(),
                'last_error' => Str::limit(class_basename($exception).': '.$exception->getMessage(), 500),
            ]);

            throw $exception;
        }

        $listed = collect($entries)->keyBy(fn (OsAdvisoryEntry $entry): string => $entry->id);
        $stored = Advisory::where('source', $os->sourceKey())->whereIn('external_id', $listed->keys())->get()->keyBy('external_id');

        $advisories = $listed
            ->filter(fn (OsAdvisoryEntry $entry, string $id): bool => $stored->has($id) || $this->isWithinCollectWindow($entry))
            ->map(fn (OsAdvisoryEntry $entry, string $id): ?Advisory => $this->store($os, $entry, $stored->get($id), $isFirstPoll))
            ->filter()
            ->values()
            ->all();

        $heartbeat->update(['last_success_at' => now(), 'last_error' => null]);

        return $advisories;
    }

    /**
     * The releases are read before each poll; when endoflife.date fails, the last ones read stand.
     */
    private function refreshReleases(LinuxDistribution $os): void
    {
        try {
            $this->releaseSupport->refresh($os);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Null when the advisory fixes no release that counts and was never stored.
     */
    private function store(LinuxDistribution $os, OsAdvisoryEntry $entry, ?Advisory $stored, bool $isFirstPoll): ?Advisory
    {
        $releases = $entry->releases;
        $packages = $entry->packages;

        if ($releases !== null && $os->keepsSupportedReleasesOnly()) {
            $releases = array_values(array_filter($releases, fn (string $release): bool => $this->releaseSupport->isSupported($os, $release)));
            $packages = array_values(array_filter($packages, fn (array $package): bool => in_array($package['release'], $releases, true)));

            if ($releases === []) {
                return $stored;
            }
        }

        $advisory = $stored ?? new Advisory([
            'source' => $os->sourceKey(),
            'external_id' => $entry->id,
        ]);

        $advisory->fill([
            'kind' => AdvisoryKind::OsAdvisory,
            'operating_system' => $os,
            'title' => Str::limit($entry->title, 250),
            'summary' => $entry->summary === null ? null : Str::limit($entry->summary, config('psm.notices.summary_length')),
            'severity' => $entry->severity,
            'url' => $entry->url !== null && strlen($entry->url) <= 255 ? $entry->url : null,
            'links' => $entry->links,
            'published_at' => $entry->publishedAt,
        ]);

        if ($releases !== null) {
            $advisory->fill([
                'os_releases' => array_map(fn (string $release): string => $this->releaseSupport->versionOf($os, $release), $releases),
                'fixed_packages' => array_map(fn (array $package): array => [...$package, 'release' => $this->releaseSupport->versionOf($os, $package['release'])], $packages),
            ]);
        }

        // A Debian advisory whose tracker page could not be read keeps the CVE ids read before.
        $advisory->aliases = $entry->cveIds === [] && $releases === null ? ($advisory->aliases ?? []) : $entry->cveIds;
        $this->matchKnownExploited->fill($advisory);

        if (! $advisory->exists) {
            $advisory->is_backfill = $isFirstPoll && ! KnownExploitation::isFresh($advisory->known_exploited);
        } elseif (KnownExploitation::isFresh($advisory->known_exploited)) {
            $advisory->is_backfill = false;
        }

        $advisory->save();

        return $advisory;
    }

    /**
     * Published within `psm.notices.collect_days` of now. An advisory without a date counts as recent.
     */
    private function isWithinCollectWindow(OsAdvisoryEntry $entry): bool
    {
        return $entry->publishedAt === null || ! $entry->publishedAt->isBefore(now()->subDays(max(config('psm.notices.collect_days'), config('psm.notices.relevant_days'))));
    }
}
