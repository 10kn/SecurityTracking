<?php

namespace App\Services\OsAdvisories;

use App\Services\Catalog\CatalogHttp;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;
use UnexpectedValueException;

/**
 * The Debian Security Advisories (DSA) from https://www.debian.org/security/dsa, an RSS 1.0 (RDF) feed of the latest
 * ones that gives a title, e.g. "DSA-6616-1 linux - security update", and a date only. The CVE ids, releases and fixed
 * source packages come from the advisory's page on the Debian security tracker, read once a day per advisory. Debian
 * rates no advisory. The security team only covers the releases in standard support: the older ones get theirs from
 * Debian LTS (DLA), which is not followed.
 */
class DebianDsaFeed implements OsAdvisoryFeed
{
    public const string URL = 'https://www.debian.org/security/dsa';

    public const string TRACKER_URL = 'https://security-tracker.debian.org/tracker/';

    private const string RSS = 'http://purl.org/rss/1.0/';

    private const string DUBLIN_CORE = 'http://purl.org/dc/elements/1.1/';

    private const string CACHE_PREFIX = 'psm:debian-dsa:';

    public function entries(): array
    {
        return array_map($this->entry(...), self::items(CatalogHttp::client()->get(self::URL)->body()));
    }

    /**
     * The feed comes from the internet: the parser never uses the network and refuses a DOCTYPE, so no entity is expanded.
     *
     * @return list<array{id: string, title: string, url: string|null, published_at: CarbonImmutable|null}>
     *
     * @throws UnexpectedValueException
     */
    private static function items(string $xml): array
    {
        if (trim($xml) === '' || stripos($xml, '<!DOCTYPE') !== false) {
            throw new UnexpectedValueException('The response is not the Debian security feed.');
        }

        $document = new DOMDocument;
        $useInternalErrors = libxml_use_internal_errors(true);

        try {
            $isLoaded = $document->loadXML($xml, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($useInternalErrors);
        }

        if (! $isLoaded || $document->documentElement?->localName !== 'RDF') {
            throw new UnexpectedValueException('The response is not the Debian security feed.');
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('rss', self::RSS);
        $xpath->registerNamespace('dc', self::DUBLIN_CORE);

        $items = [];

        foreach ($xpath->query('//rss:item') ?: [] as $item) {
            $title = Str::squish($xpath->evaluate('string(rss:title)', $item));

            if (preg_match('/^(DSA-\d+-\d+)\s+(.+)$/', $title, $matches) !== 1) {
                continue;
            }

            $link = trim($xpath->evaluate('string(rss:link)', $item));

            $items[] = [
                'id' => $matches[1],
                'title' => "{$matches[1]}: {$matches[2]}",
                'url' => preg_match('#^https?://#i', $link) === 1 ? $link : null,
                'published_at' => self::date($xpath->evaluate('string(dc:date)', $item)),
            ];
        }

        return $items;
    }

    /**
     * @param  array{id: string, title: string, url: string|null, published_at: CarbonImmutable|null}  $item
     */
    private function entry(array $item): OsAdvisoryEntry
    {
        $details = $this->details($item['id']);

        return new OsAdvisoryEntry(
            id: $item['id'],
            title: $item['title'],
            url: $item['url'],
            publishedAt: $item['published_at'],
            cveIds: $details['cves'] ?? [],
            releases: $details === null ? null : array_values(array_unique(array_column($details['packages'], 'release'))),
            packages: $details['packages'] ?? [],
            links: [self::TRACKER_URL.$item['id']],
        );
    }

    /**
     * What the security tracker says of the advisory, kept for a day. Null when the page could not be read: the
     * advisory is stored without it and completed on a later poll.
     *
     * @return array{cves: list<string>, packages: list<array{release: string, name: string, version: string|null}>}|null
     */
    private function details(string $id): ?array
    {
        $key = self::CACHE_PREFIX.$id;

        if (Cache::has($key)) {
            return Cache::get($key);
        }

        try {
            $details = self::trackerPage(CatalogHttp::client()->get(self::TRACKER_URL.$id)->body());
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        Cache::put($key, $details, now()->addDay());

        return $details;
    }

    /**
     * The CVE ids the page links to and its table of fixed versions: Package, Type, Release, Fixed Version, … Only
     * source packages with a fixed version count.
     *
     * @return array{cves: list<string>, packages: list<array{release: string, name: string, version: string|null}>}
     */
    public static function trackerPage(string $html): array
    {
        $document = new DOMDocument;
        $useInternalErrors = libxml_use_internal_errors(true);

        try {
            $isLoaded = trim($html) !== '' && $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($useInternalErrors);
        }

        if (! $isLoaded) {
            throw new UnexpectedValueException('The response is not a Debian security tracker page.');
        }

        $xpath = new DOMXPath($document);

        $cves = collect(iterator_to_array($xpath->query('//a') ?: []))
            ->map(fn (DOMElement $link): string => strtoupper(trim($link->textContent)))
            ->filter(fn (string $text): bool => preg_match('/^CVE-\d{4}-\d{4,}$/', $text) === 1)
            ->unique()
            ->values()
            ->all();

        $packages = [];

        foreach ($xpath->query('//table[tr/th[normalize-space(.)="Fixed Version"]]/tr[td]') ?: [] as $row) {
            $cells = array_map(fn (DOMElement $cell): string => Str::squish($cell->textContent), iterator_to_array($xpath->query('td', $row) ?: []));
            [$name, $type, $release, $version] = array_pad($cells, 4, '');

            if ($name === '' || $type !== 'source' || $release === '' || $version === '' || str_starts_with($version, '(')) {
                continue;
            }

            $packages[] = ['release' => $release, 'name' => $name, 'version' => $version];
        }

        return ['cves' => $cves, 'packages' => $packages];
    }

    private static function date(string $value): ?CarbonImmutable
    {
        try {
            return trim($value) === '' ? null : CarbonImmutable::parse($value, 'UTC')->utc();
        } catch (Throwable) {
            return null;
        }
    }
}
