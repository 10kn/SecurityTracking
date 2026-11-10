<?php

namespace App\Services\Notices;

use App\Enums\Severity;

/**
 * One security advisory a vendor published on GitHub, e.g. GHSA-rgrp-mwpx-f6rm of php/php-src: its ids, how severe the
 * vendor rates it, and the release of each branch that fixes it.
 */
final readonly class VendorSecurityAdvisory
{
    /**
     * @param  list<string>  $patchedVersions  e.g. ["8.2.34", "8.3.35", "8.4.26", "8.5.11"]
     */
    public function __construct(
        public string $ghsaId,
        public ?string $cveId,
        public Severity $severity,
        public ?float $cvssScore,
        public array $patchedVersions,
    ) {}

    /**
     * @return array{ghsa_id: string, cve_id: string|null, severity: string, cvss_score: float|null, patched_versions: list<string>}
     */
    public function toArray(): array
    {
        return [
            'ghsa_id' => $this->ghsaId,
            'cve_id' => $this->cveId,
            'severity' => $this->severity->value,
            'cvss_score' => $this->cvssScore,
            'patched_versions' => $this->patchedVersions,
        ];
    }

    /**
     * @param  array{ghsa_id: string, cve_id: string|null, severity: string, cvss_score: float|null, patched_versions: list<string>}  $attributes
     */
    public static function fromArray(array $attributes): self
    {
        return new self($attributes['ghsa_id'], $attributes['cve_id'], Severity::from($attributes['severity']), $attributes['cvss_score'], $attributes['patched_versions']);
    }
}
