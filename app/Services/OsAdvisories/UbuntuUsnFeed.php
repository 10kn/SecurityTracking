<?php

namespace App\Services\OsAdvisories;

use App\Services\Catalog\CatalogHttp;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;
use UnexpectedValueException;

/**
 * The Ubuntu Security Notices (USN), newest first, from the JSON API of https://ubuntu.com/security/notices: the
 * releases each fixes, with the packages per release codename, and its CVE ids. A release counts only when one of its
 * fixed binary packages is in a free pocket ("security", "updates"): a fix only in an `esm-*` pocket needs Ubuntu Pro,
 * which the free images do not have. Notices give no rating: the severity is the highest Ubuntu priority of their CVE
 * ids (see UbuntuCvePriorities).
 */
class UbuntuUsnFeed implements OsAdvisoryFeed
{
    public const string URL = 'https://ubuntu.com/security/notices.json';

    /**
     * The most notices the API returns at once.
     */
    public const int PAGE_SIZE = 20;

    /**
     * About ten days of notices, polled every 30 minutes.
     */
    public const int PAGES = 3;

    public function __construct(private UbuntuCvePriorities $priorities) {}

    public function entries(): array
    {
        $notices = array_values(array_filter($this->notices(), fn (mixed $notice): bool => is_array($notice) && filled($notice['id'] ?? null) && ($notice['is_hidden'] ?? false) !== true));

        // Only the notices with a free fix are rated: the others are left out (see SyncOsAdvisoryFeed).
        $this->priorities->lookUp(array_merge(...array_map(fn (array $notice): array => self::freeFixes($notice) === [] ? [] : self::cveIds($notice), $notices)));

        return array_map($this->entry(...), $notices);
    }

    /**
     * The latest PAGES pages of notices. ubuntu.com often answers 503: the first page must be read, a later one that
     * fails ends the list, as the next poll reads it again.
     *
     * @return list<mixed>
     *
     * @throws UnexpectedValueException When the first page is not the list of notices.
     */
    private function notices(): array
    {
        $notices = [];

        for ($page = 0; $page < self::PAGES; $page++) {
            try {
                $list = CatalogHttp::client()->get(self::URL, ['limit' => self::PAGE_SIZE, 'offset' => $page * self::PAGE_SIZE])->json('notices');

                if (! is_array($list)) {
                    throw new UnexpectedValueException('The response is not the list of Ubuntu security notices.');
                }
            } catch (Throwable $exception) {
                if ($page === 0) {
                    throw $exception;
                }

                report($exception);

                break;
            }

            array_push($notices, ...array_values($list));

            if (count($list) < self::PAGE_SIZE) {
                break;
            }
        }

        return $notices;
    }

    /**
     * @param  array<string, mixed>  $notice
     */
    private function entry(array $notice): OsAdvisoryEntry
    {
        $id = (string) $notice['id'];
        $versions = self::versions($notice);
        $packages = collect(self::freeFixes($notice));
        $cveIds = self::cveIds($notice);

        return new OsAdvisoryEntry(
            id: $id,
            title: "{$id}: ".Str::squish((string) ($notice['title'] ?? '')),
            url: "https://ubuntu.com/security/notices/{$id}",
            publishedAt: self::date($notice['published'] ?? null),
            severity: $this->priorities->severity($cveIds),
            cveIds: $cveIds,
            releases: $packages->keys()->map(fn (string $codename): string => $versions[$codename])->sort(fn (string $a, string $b): int => version_compare($b, $a))->values()->all(),
            packages: $packages
                ->flatMap(fn (array $packages, string $codename): array => collect($packages)
                    ->filter(fn (array $package): bool => ($package['is_source'] ?? false) === true)
                    ->map(fn (array $package): array => ['release' => $versions[$codename], 'name' => (string) $package['name'], 'version' => isset($package['version']) ? (string) $package['version'] : null])
                    ->all())
                ->values()
                ->all(),
            summary: filled($notice['summary'] ?? null) ? Str::squish((string) $notice['summary']) : null,
        );
    }

    /**
     * The packages of each release the notice fixes for free, by codename.
     *
     * @param  array<string, mixed>  $notice
     * @return array<string, array<int, array<string, mixed>>>
     */
    private static function freeFixes(array $notice): array
    {
        $versions = self::versions($notice);

        return collect((array) ($notice['release_packages'] ?? []))
            ->filter(fn (mixed $packages, string $codename): bool => is_array($packages) && $versions->has($codename) && self::hasFreeFix($packages))
            ->all();
    }

    /**
     * The version of each release codename, e.g. "noble" => "24.04".
     *
     * @param  array<string, mixed>  $notice
     * @return Collection<string, string>
     */
    private static function versions(array $notice): Collection
    {
        return collect((array) ($notice['releases'] ?? []))
            ->filter(fn (mixed $release): bool => is_array($release) && isset($release['codename'], $release['version']))
            ->mapWithKeys(fn (array $release): array => [(string) $release['codename'] => (string) $release['version']]);
    }

    /**
     * A release whose notice lists no binary package with a pocket is kept: nothing says its fix needs Ubuntu Pro.
     *
     * @param  array<int, array<string, mixed>>  $packages
     */
    private static function hasFreeFix(array $packages): bool
    {
        $pockets = collect($packages)->reject(fn (array $package): bool => ($package['is_source'] ?? false) === true)->pluck('pocket')->filter()->map(fn (mixed $pocket): string => (string) $pocket);

        return $pockets->isEmpty() || $pockets->contains(fn (string $pocket): bool => ! str_starts_with($pocket, 'esm'));
    }

    /**
     * @param  array<string, mixed>  $notice
     * @return list<string>
     */
    private static function cveIds(array $notice): array
    {
        return collect((array) ($notice['cves_ids'] ?? []))
            ->map(fn (mixed $id): string => strtoupper((string) $id))
            ->filter(fn (string $id): bool => preg_match('/^CVE-\d{4}-\d{4,}$/', $id) === 1)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Ubuntu gives the time without a zone; it is UTC.
     */
    private static function date(mixed $value): ?CarbonImmutable
    {
        try {
            return is_string($value) && $value !== '' ? CarbonImmutable::parse($value, 'UTC') : null;
        } catch (Throwable) {
            return null;
        }
    }
}
