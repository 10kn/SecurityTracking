<?php

namespace App\Services\Kev;

use Carbon\CarbonImmutable;

/**
 * One vulnerability of the CISA Known Exploited Vulnerabilities catalog: CISA has reliable evidence that it is exploited
 * in the wild. `dueDate` is the date by which US federal agencies must remediate it.
 */
final readonly class KevEntry
{
    public function __construct(
        public string $cveId,
        public string $vendorProject,
        public string $product,
        public string $name,
        public ?string $shortDescription,
        public CarbonImmutable $dateAdded,
        public ?CarbonImmutable $dueDate,
        public ?string $ransomwareUse,
    ) {}
}
