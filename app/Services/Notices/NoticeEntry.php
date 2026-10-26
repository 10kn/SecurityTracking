<?php

namespace App\Services\Notices;

use App\Enums\Severity;
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

    /**
     * The versions the entry tells to update to, e.g. ["8.5.12", "8.4.27", "8.3.36", "8.2.35"] from "Update to PHP
     * 8.5.12, 8.4.27, 8.3.36 or 8.2.35." Only versions right after "update/upgrade … to", "fixed in" or "patched in"
     * count, up to the end of the sentence (a dot followed by a space or the end of the text, so "Node.js 22.20.1" and
     * "ruby-lang.org" do not end it), a clause naming other versions ("if you run …", "for …", "from …") or one
     * setting versions apart ("but not …", "however …", "although …", "excluding …"): "fixed in PHP 8.3.36 but not PHP
     * 8.4.26" gives only 8.3.36. A negated phrase ("not fixed in") and pre-releases ("8.3.36-rc1") do not count, nor do
     * other versions in the text.
     *
     * @return list<string>
     */
    public function patchVersions(): array
    {
        $text = "{$this->title}. {$this->text}";

        preg_match_all('/\b(?:(?:update|upgrade|updating|upgrading)(?:\s+[\w.-]+){0,3}?\s+to|(?:fixed|patched)\s+in)\b((?:[^.;:!?]|\.(?!\s|$)){0,200})/i', $text, $sentences, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        return collect($sentences)
            ->reject(fn (array $match): bool => preg_match('/\b(?:not|never|no longer|cannot|won\'t|isn\'t|aren\'t)\s+(?:\w+\s+){0,2}$/i', substr($text, max(0, $match[0][1] - 30), min(30, $match[0][1]))) === 1)
            ->map(fn (array $match): string => preg_split('/\b(?:if|when|while|unless|for|from|on|running|affected|except|excluding|but|not|however|although|though|whereas|yet)\b/i', $match[1][0])[0])
            ->flatMap(fn (string $sentence): array => preg_match_all('/(?<![\d.])v?(\d+\.\d+\.\d+)(?![\d.]*\d|-|\w)/i', $sentence, $versions) > 0 ? $versions[1] : [])
            ->unique()
            ->values()
            ->all();
    }

    public function summary(int $length): ?string
    {
        return $this->text === '' ? null : Str::limit($this->text, $length);
    }
}
