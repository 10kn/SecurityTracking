<?php

namespace App\Services\Notices;

use App\Enums\Severity;
use App\Enums\Technology;
use App\Services\Catalog\CatalogHttp;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The security advisories a vendor publishes on GitHub (see Technology::securityAdvisoryRepository()), e.g. those of
 * php/php-src. A release announcement often names no CVE and gives no severity ("PHP 8.4.26 Released! This is a
 * security release"), while the advisories the release fixes have both: they are found by the versions the release
 * announcement updates to.
 *
 * The latest page of advisories (`psm.notices.vendor_advisories.per_page`) is read once per instance, so once per poll,
 * and only when a pre-notice asks for it. GitHub is asked whether it changed since the last read (ETag): an unchanged
 * list costs no download. When GitHub cannot be read, the error is reported and the list read before stands, so a
 * pre-notice keeps what it got from it.
 */
class VendorSecurityAdvisories
{
    /**
     * @var array<string, list<VendorSecurityAdvisory>> By repository.
     */
    private array $read = [];

    /**
     * The advisories that one of the versions fixes, by GHSA id.
     *
     * @param  list<string>  $versions  e.g. ["8.4.26"]
     * @return list<VendorSecurityAdvisory>
     */
    public function fixedIn(Technology $technology, array $versions): array
    {
        $repository = $technology->securityAdvisoryRepository();

        if ($versions === [] || $repository === null || blank(config('psm.notices.vendor_advisories.url'))) {
            return [];
        }

        $this->read[$repository] ??= $this->advisoriesOf($repository);

        return collect($this->read[$repository])
            ->filter(fn (VendorSecurityAdvisory $advisory): bool => array_intersect($advisory->patchedVersions, $versions) !== [])
            ->sortBy('ghsaId')
            ->values()
            ->all();
    }

    /**
     * @return list<VendorSecurityAdvisory>
     */
    private function advisoriesOf(string $repository): array
    {
        $cacheKey = "notices:vendor-advisories:{$repository}";
        $cached = Cache::get($cacheKey);
        $cached = is_array($cached) && is_array($cached['advisories'] ?? null) ? $cached : null;

        try {
            $response = CatalogHttp::client(config('psm.notices.vendor_advisories.http_timeout_seconds'))
                ->accept('application/vnd.github+json')
                ->withHeaders($cached === null || blank($cached['etag'] ?? null) ? [] : ['If-None-Match' => $cached['etag']])
                ->get(str_replace('{repository}', $repository, (string) config('psm.notices.vendor_advisories.url')), [
                    'state' => 'published',
                    'sort' => 'published',
                    'direction' => 'desc',
                    'per_page' => config('psm.notices.vendor_advisories.per_page'),
                ]);
        } catch (Throwable $exception) {
            report($exception);

            return $this->fromCache($cached);
        }

        if ($response->status() === 304 && $cached !== null) {
            return $this->fromCache($cached);
        }

        $advisories = collect(is_array($response->json()) ? $response->json() : [])
            ->filter(fn (mixed $advisory): bool => is_array($advisory))
            ->map(fn (array $advisory): ?VendorSecurityAdvisory => $this->parse($advisory))
            ->filter()
            ->values();

        Cache::forever($cacheKey, [
            'etag' => $response->header('ETag') ?: null,
            'advisories' => $advisories->map(fn (VendorSecurityAdvisory $advisory): array => $advisory->toArray())->all(),
        ]);

        return $advisories->all();
    }

    /**
     * @param  array{etag?: string|null, advisories: list<array{ghsa_id: string, cve_id: string|null, severity: string, cvss_score: float|null, patched_versions: list<string>}>}|null  $cached
     * @return list<VendorSecurityAdvisory>
     */
    private function fromCache(?array $cached): array
    {
        return array_map(VendorSecurityAdvisory::fromArray(...), $cached['advisories'] ?? []);
    }

    /**
     * A published advisory with a GHSA id and at least one patched release. The severity is the vendor's, else the
     * one its CVSS score gives; the score is the highest CVSS version it has.
     *
     * @param  array<string, mixed>  $advisory
     */
    private function parse(array $advisory): ?VendorSecurityAdvisory
    {
        $ghsaId = $advisory['ghsa_id'] ?? null;

        if (! is_string($ghsaId) || preg_match('/^GHSA(?:-[23456789cfghjmpqrvwx]{4}){3}$/i', $ghsaId) !== 1 || filled($advisory['withdrawn_at'] ?? null)) {
            return null;
        }

        $patchedVersions = collect(is_array($advisory['vulnerabilities'] ?? null) ? $advisory['vulnerabilities'] : [])
            ->flatMap(function (mixed $vulnerability): array {
                preg_match_all('/\b\d+\.\d+\.\d+\b/', is_array($vulnerability) && is_string($vulnerability['patched_versions'] ?? null) ? $vulnerability['patched_versions'] : '', $matches);

                return $matches[0];
            })
            ->unique()
            ->sort(SORT_NATURAL)
            ->values()
            ->all();

        if ($patchedVersions === []) {
            return null;
        }

        $cveId = is_string($advisory['cve_id'] ?? null) && preg_match('/^CVE-\d{4}-\d{4,}$/i', $advisory['cve_id']) === 1 ? strtoupper($advisory['cve_id']) : null;
        $scores = collect([$advisory['cvss']['score'] ?? null, $advisory['cvss_severities']['cvss_v3']['score'] ?? null, $advisory['cvss_severities']['cvss_v4']['score'] ?? null])
            ->filter(fn (mixed $score): bool => is_numeric($score) && $score >= 0 && $score <= 10)
            ->map(fn (mixed $score): float => (float) $score);
        $cvssScore = $scores->max();
        $severityName = strtolower((string) ($advisory['severity'] ?? ''));
        $severity = Severity::tryFrom($severityName === 'moderate' ? 'medium' : $severityName) ?? Severity::Unknown;

        return new VendorSecurityAdvisory(
            ghsaId: 'GHSA-'.strtolower(substr($ghsaId, 5)),
            cveId: $cveId,
            severity: $severity === Severity::Unknown && $cvssScore !== null ? Severity::fromCvss($cvssScore) : $severity,
            cvssScore: $cvssScore,
            patchedVersions: $patchedVersions,
        );
    }
}
