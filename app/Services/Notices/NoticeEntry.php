<?php

namespace App\Services\Notices;

use App\Enums\Severity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * One feed entry as plain text. `externalId` is the entry's id, link or guid, whichever the feed keeps stable.
 */
final readonly class NoticeEntry
{
    public function __construct(
        public string $externalId,
        public string $title,
        public string $text = '',
        public ?string $url = null,
        public ?CarbonImmutable $publishedAt = null,
    ) {}

    /**
     * @param  list<string>  $keywords  Matched anywhere in the title or text, ignoring case.
     */
    public function mentionsAny(array $keywords): bool
    {
        return $keywords !== [] && Str::contains("{$this->title} {$this->text}", $keywords, ignoreCase: true);
    }

    /**
     * Critical when the vendor calls it critical, High when it says high, otherwise unknown.
     */
    public function severity(): Severity
    {
        $text = "{$this->title} {$this->text}";

        return match (true) {
            preg_match('/\bcritical\b/i', $text) === 1 => Severity::Critical,
            preg_match('/\bhigh\b/i', $text) === 1 => Severity::High,
            default => Severity::Unknown,
        };
    }

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

    public function summary(int $length): ?string
    {
        return $this->text === '' ? null : Str::limit($this->text, $length);
    }
}
