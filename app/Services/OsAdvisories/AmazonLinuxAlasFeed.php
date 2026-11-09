<?php

namespace App\Services\OsAdvisories;

use App\Enums\Severity;
use App\Services\Catalog\CatalogHttp;
use App\Services\Notices\NewsFeedParser;
use App\Services\Notices\NoticeEntry;

/**
 * The Amazon Linux Security Advisories (ALAS) of one major version, an RSS feed of its whole history. Each title has a
 * fixed format, e.g. "ALAS2023-2026-3338 (important): emacs": the id, Amazon's rating and the packages it fixes. The
 * description lists the CVE ids. Amazon Linux 2 includes its extras, e.g. "ALAS2DOCKER-2026-156 (medium): containerd".
 */
class AmazonLinuxAlasFeed implements OsAdvisoryFeed
{
    public const string AL2023_URL = 'https://alas.aws.amazon.com/AL2023/alas.rss';

    public const string AL2_URL = 'https://alas.aws.amazon.com/AL2/alas.rss';

    /**
     * The feed lists thousands of advisories: a few megabytes.
     */
    private const int TIMEOUT_SECONDS = 60;

    public function __construct(private string $url, private string $release) {}

    public function entries(): array
    {
        $items = NewsFeedParser::parse(CatalogHttp::client(self::TIMEOUT_SECONDS)->get($this->url)->body());

        return array_values(array_filter(array_map($this->entry(...), $items)));
    }

    /**
     * Null for an item whose title does not have the ALAS format.
     */
    private function entry(NoticeEntry $item): ?OsAdvisoryEntry
    {
        if (preg_match('/^(ALAS[0-9A-Z.\-]*?-\d{4}-\d+)\s*\((\w+)\):\s*(.+)$/', $item->title, $matches) !== 1) {
            return null;
        }

        [, $id, $rating, $packages] = $matches;

        return new OsAdvisoryEntry(
            id: $id,
            title: "{$id}: {$packages}",
            url: $item->url,
            publishedAt: $item->publishedAt,
            severity: self::severity($rating),
            cveIds: $item->cveIds(),
            releases: [$this->release],
            packages: array_map(fn (string $name): array => ['release' => $this->release, 'name' => $name, 'version' => null], array_values(array_filter(array_map('trim', explode(',', $packages))))),
        );
    }

    /**
     * Amazon rates advisories critical, important, medium or low.
     */
    private static function severity(string $rating): Severity
    {
        return match (strtolower($rating)) {
            'critical' => Severity::Critical,
            'important' => Severity::High,
            'medium' => Severity::Medium,
            'low' => Severity::Low,
            default => Severity::Unknown,
        };
    }
}
