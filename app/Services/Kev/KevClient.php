<?php

namespace App\Services\Kev;

use App\Services\Catalog\CatalogHttp;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Throwable;
use UnexpectedValueException;

/**
 * Reads the CISA Known Exploited Vulnerabilities catalog (https://www.cisa.gov/known-exploited-vulnerabilities-catalog)
 * from the JSON file CISA publishes at `psm.kev.url`.
 */
class KevClient
{
    /**
     * Every entry of the catalog, and the catalog version, e.g. "2026.10.26". Only a complete catalog is returned: what
     * it does not list counts as removed by CISA, so a row that cannot be read fails the whole read.
     *
     * @return array{version: string|null, entries: list<KevEntry>}
     *
     * @throws ConnectionException
     * @throws RequestException
     * @throws UnexpectedValueException When the response is not the catalog, lists no entry or a row it cannot read.
     */
    public function catalog(): array
    {
        $catalog = CatalogHttp::client(config('psm.kev.http_timeout_seconds'))->get((string) config('psm.kev.url'))->json();

        if (! is_array($catalog) || ! is_array($catalog['vulnerabilities'] ?? null) || $catalog['vulnerabilities'] === []) {
            throw new UnexpectedValueException('CISA KEV did not return a catalog of vulnerabilities.');
        }

        $entries = [];

        foreach ($catalog['vulnerabilities'] as $vulnerability) {
            $entry = is_array($vulnerability) ? self::entry($vulnerability) : null;

            if ($entry === null) {
                throw new UnexpectedValueException('CISA KEV listed a vulnerability without a CVE id or the date it was added.');
            }

            $entries[$entry->cveId] = $entry;
        }

        if (is_int($catalog['count'] ?? null) && $catalog['count'] !== count($catalog['vulnerabilities'])) {
            throw new UnexpectedValueException('CISA KEV listed another number of vulnerabilities than its count.');
        }

        return ['version' => is_string($catalog['catalogVersion'] ?? null) ? $catalog['catalogVersion'] : null, 'entries' => array_values($entries)];
    }

    /**
     * Null for an entry without a CVE id or the date it was added.
     *
     * @param  array<string, mixed>  $vulnerability
     */
    private static function entry(array $vulnerability): ?KevEntry
    {
        $cveId = strtoupper(trim((string) ($vulnerability['cveID'] ?? '')));
        $dateAdded = self::date($vulnerability['dateAdded'] ?? null);

        if (preg_match('/^CVE-\d{4}-\d{4,}$/', $cveId) !== 1 || $dateAdded === null) {
            return null;
        }

        return new KevEntry(
            cveId: $cveId,
            vendorProject: (string) ($vulnerability['vendorProject'] ?? ''),
            product: (string) ($vulnerability['product'] ?? ''),
            name: (string) ($vulnerability['vulnerabilityName'] ?? $cveId),
            shortDescription: is_string($vulnerability['shortDescription'] ?? null) ? $vulnerability['shortDescription'] : null,
            dateAdded: $dateAdded,
            dueDate: self::date($vulnerability['dueDate'] ?? null),
            ransomwareUse: is_string($vulnerability['knownRansomwareCampaignUse'] ?? null) ? $vulnerability['knownRansomwareCampaignUse'] : null,
        );
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value) ?: null;
        } catch (Throwable) {
            return null;
        }
    }
}
