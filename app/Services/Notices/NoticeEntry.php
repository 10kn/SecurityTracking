<?php

namespace App\Services\Notices;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * One feed entry as plain text. `externalId` is the entry's id, link or guid, whichever the feed keeps stable.
 * `links` are the absolute addresses the entry links to, normalized (see NoticeUrl).
 */
final readonly class NoticeEntry
{
    /**
     * @param  list<string>  $links
     */
    public function __construct(
        public string $externalId,
        public string $title,
        public string $text = '',
        public ?string $url = null,
        public ?CarbonImmutable $publishedAt = null,
        public array $links = [],
    ) {}

    /**
     * The highest CVSS score the text gives, e.g. 9.2 from "both rated CVSS v4.0 9.2". Vector strings such as
     * "CVSS:3.1/AV:N" are not scores.
     */
    public function cvssScore(): ?float
    {
        preg_match_all('/\bCVSS(?:\s*v?\d\.\d)?(?:\s+(?:base\s+)?score)?(?:\s+of)?[:\s]+(\d{1,2}(?:\.\d)?)(?!\d|\.\d|\/)/i', "{$this->title} {$this->text}", $matches);

        $scores = array_filter(array_map('floatval', $matches[1]), fn (float $score): bool => $score >= 0 && $score <= 10);

        return $scores === [] ? null : max($scores);
    }

    /**
     * CVE ids the entry mentions, e.g. ["CVE-2026-109618"].
     *
     * @return list<string>
     */
    public function cveIds(): array
    {
        preg_match_all('/\bCVE-\d{4}-\d{4,}\b/i', "{$this->title} {$this->text}", $matches);

        return array_values(array_unique(array_map('strtoupper', $matches[0])));
    }

    /**
     * GitHub security advisory ids the entry mentions or links to, e.g. ["GHSA-w8hw-w9fx-j79w"].
     *
     * @return list<string>
     */
    public function ghsaIds(): array
    {
        preg_match_all('/\bGHSA(?:-[23456789cfghjmpqrvwx]{4}){3}\b/i', implode(' ', [$this->title, $this->text, ...$this->links]), $matches);

        return array_values(array_unique(array_map(fn (string $id): string => 'GHSA-'.strtolower(substr($id, 5)), $matches[0])));
    }

    /**
     * The CVE and GHSA ids of the entry: the ids that tie it to other news of the same issue.
     *
     * @return list<string>
     */
    public function referenceIds(): array
    {
        return [...$this->cveIds(), ...$this->ghsaIds()];
    }

    private function plainText(): string
    {
        return Str::squish("{$this->title}. {$this->text}");
    }

    /**
     * Whether a sentence the classifier quoted from the entry is in it, ignoring case and spacing. Too short a quote
     * proves nothing.
     */
    public function quotes(string $excerpt): bool
    {
        $excerpt = Str::squish($excerpt);

        return mb_strlen($excerpt) >= 12 && mb_stripos($this->plainText(), $excerpt) !== false;
    }

    public function summary(int $length): ?string
    {
        return $this->text === '' ? null : Str::limit($this->text, $length);
    }
}
