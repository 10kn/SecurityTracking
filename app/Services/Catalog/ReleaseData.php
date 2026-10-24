<?php

namespace App\Services\Catalog;

use Carbon\CarbonImmutable;

/**
 * One published version. `isSecurityRelease` is null when the source does not say whether it fixes vulnerabilities.
 */
final readonly class ReleaseData
{
    public function __construct(
        public string $version,
        public ?bool $isSecurityRelease = null,
        public ?CarbonImmutable $releasedAt = null,
    ) {}
}
