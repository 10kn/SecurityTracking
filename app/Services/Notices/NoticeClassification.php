<?php

namespace App\Services\Notices;

use App\Enums\Severity;
use Carbon\CarbonImmutable;

/**
 * What a notice classifier read from one feed entry.
 */
final readonly class NoticeClassification
{
    /**
     * @param  list<string>  $affectedBranches  Release branches the entry names as affected, e.g. ["8.3", "8.4"].
     * @param  ?string  $summary  A short summary in the alert locale.
     * @param  list<string>  $unaffectedBranches  Release branches the entry explicitly says are not affected.
     * @param  ?int  $relatedIncidentId  The incident, among those offered, the classifier thinks the entry is about.
     *                                   Only a suggestion for an admin.
     */
    public function __construct(
        public bool $isSecurityNews,
        public Severity $severity,
        public ?CarbonImmutable $plannedReleaseAt = null,
        public array $affectedBranches = [],
        public ?string $summary = null,
        public array $unaffectedBranches = [],
        public ?int $relatedIncidentId = null,
    ) {}
}
