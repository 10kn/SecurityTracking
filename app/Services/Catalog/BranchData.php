<?php

namespace App\Services\Catalog;

use Carbon\CarbonImmutable;

/**
 * One release branch (endoflife.date calls it a cycle), e.g. PHP "8.3" or Node.js "20". `label` is the name people
 * know it by, e.g. "13 (Trixie)" for Debian 13.
 */
final readonly class BranchData
{
    public function __construct(
        public string $branch,
        public ?string $latestVersion,
        public ?CarbonImmutable $releasedAt,
        public ?CarbonImmutable $activeSupportUntil,
        public ?CarbonImmutable $securitySupportUntil,
        public bool $isEol,
        public ?string $label = null,
    ) {}
}
