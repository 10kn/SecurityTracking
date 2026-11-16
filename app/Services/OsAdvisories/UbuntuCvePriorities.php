<?php

namespace App\Services\OsAdvisories;

use App\Enums\Severity;
use App\Services\Catalog\CatalogHttp;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The priority Ubuntu gives each CVE id (negligible, low, medium, high or critical), which its notices do not repeat.
 * It is read from OSV, which mirrors the Ubuntu CVE tracker as `UBUNTU-CVE-…` records and answers faster and more
 * reliably than ubuntu.com. Priorities are cached for `psm.os_advisories.ubuntu_cve_cache_days`; a CVE Ubuntu has not
 * rated yet for a day.
 */
class UbuntuCvePriorities
{
    private const string CACHE_PREFIX = 'psm:ubuntu-cve-priority:';

    /**
     * Looks up the priority of the CVE ids not cached yet, in order, at most `psm.os_advisories.ubuntu_cve_lookups` per
     * call: a kernel notice lists hundreds, the rest are looked up on later polls. A failed lookup is left for the next
     * poll and does not fail the feed.
     *
     * @param  list<string>  $cveIds
     */
    public function lookUp(array $cveIds): void
    {
        $missing = collect($cveIds)
            ->unique()
            ->reject(fn (string $cveId): bool => Cache::has(self::CACHE_PREFIX.$cveId))
            ->take(max(0, (int) config('psm.os_advisories.ubuntu_cve_lookups')))
            ->values()
            ->all();

        if ($missing === []) {
            return;
        }

        $responses = Http::pool(fn (Pool $pool): array => array_map(
            fn (string $cveId) => CatalogHttp::configure($pool->as($cveId), config('psm.packages.http_timeout_seconds'))->get($this->url($cveId)),
            $missing,
        ), concurrency: max(1, (int) config('psm.packages.concurrency')));

        foreach ($missing as $cveId) {
            $response = $responses[$cveId] ?? null;

            if ($response instanceof RequestException && $response->response->notFound()) {
                Cache::put(self::CACHE_PREFIX.$cveId, '', now()->addDay());

                continue;
            }

            if ($response instanceof Throwable || ! $response instanceof Response) {
                continue;
            }

            $priority = collect((array) $response->json('severity', []))->firstWhere('type', 'Ubuntu')['score'] ?? '';

            Cache::put(self::CACHE_PREFIX.$cveId, strtolower((string) $priority), now()->addDays((int) config('psm.os_advisories.ubuntu_cve_cache_days')));
        }
    }

    /**
     * The highest priority of the CVE ids looked up so far, as a severity: negligible counts as Low. Unknown when none
     * is known yet.
     *
     * @param  list<string>  $cveIds
     */
    public function severity(array $cveIds): Severity
    {
        return collect($cveIds)
            ->map(fn (string $cveId): Severity => self::severityOf((string) Cache::get(self::CACHE_PREFIX.$cveId, '')))
            ->sortByDesc(fn (Severity $severity): int => $severity->rank())
            ->first() ?? Severity::Unknown;
    }

    private static function severityOf(string $priority): Severity
    {
        return match ($priority) {
            'critical' => Severity::Critical,
            'high' => Severity::High,
            'medium' => Severity::Medium,
            'low', 'negligible' => Severity::Low,
            default => Severity::Unknown,
        };
    }

    private function url(string $cveId): string
    {
        return rtrim((string) config('psm.packages.osv_url'), '/').'/vulns/UBUNTU-'.rawurlencode($cveId);
    }
}
