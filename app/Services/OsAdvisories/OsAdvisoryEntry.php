<?php

namespace App\Services\OsAdvisories;

use App\Enums\Severity;
use Carbon\CarbonImmutable;

/**
 * One advisory of an operating system, e.g. "USN-8999-1: Pillow vulnerabilities". `releases` are the releases it
 * fixes as the feed names them (a version such as "24.04", or a Debian codename such as "trixie"), null when the feed
 * could not tell, and `packages` the source packages that fix it in each, with their version when the feed gives one.
 */
final readonly class OsAdvisoryEntry
{
    /**
     * @param  list<string>  $cveIds
     * @param  list<string>|null  $releases
     * @param  list<array{release: string, name: string, version: string|null}>  $packages
     * @param  list<string>  $links
     */
    public function __construct(
        public string $id,
        public string $title,
        public ?string $url,
        public ?CarbonImmutable $publishedAt,
        public Severity $severity = Severity::Unknown,
        public array $cveIds = [],
        public ?array $releases = null,
        public array $packages = [],
        public ?string $summary = null,
        public array $links = [],
    ) {}
}
