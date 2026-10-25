<?php

namespace App\Models;

use App\Enums\AdvisoryKind;
use App\Enums\Ecosystem;
use App\Enums\Severity;
use App\Enums\Technology;
use App\Services\Packages\PackagistVulnerabilityMatcher;
use Database\Factories\AdvisoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['source', 'external_id', 'kind', 'aliases', 'title', 'summary', 'severity', 'cvss_score', 'technology', 'url', 'published_at', 'source_modified_at', 'withdrawn_at', 'fixed_versions', 'content_hash', 'classified_by', 'classified_severity', 'is_dismissed', 'planned_release_at', 'affected_branches', 'localized_summary'])]
class Advisory extends Model
{
    /** @use HasFactory<AdvisoryFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => AdvisoryKind::class,
            'aliases' => 'array',
            'severity' => Severity::class,
            'cvss_score' => 'decimal:1',
            'technology' => Technology::class,
            'published_at' => 'datetime',
            'source_modified_at' => 'datetime',
            'withdrawn_at' => 'datetime',
            'fixed_versions' => 'array',
            'classified_severity' => Severity::class,
            'is_dismissed' => 'boolean',
            'planned_release_at' => 'datetime',
            'affected_branches' => 'array',
        ];
    }

    /**
     * @return HasMany<Finding, $this>
     */
    public function findings(): HasMany
    {
        return $this->hasMany(Finding::class);
    }

    /**
     * The id people know the advisory by, for titles and cards. Packagist's own PKSA id is only the stable key: its
     * GitHub id, else its CVE, is shown instead.
     */
    public function reference(): string
    {
        if ($this->source !== PackagistVulnerabilityMatcher::SOURCE) {
            return $this->external_id;
        }

        $aliases = collect($this->aliases ?? []);

        return $aliases->first(fn (string $alias): bool => str_starts_with($alias, 'GHSA-'))
            ?? $aliases->first(fn (string $alias): bool => str_starts_with($alias, 'CVE-'))
            ?? $this->external_id;
    }

    /**
     * The lowest version that fixes the advisory for the package and is newer than the installed one, read from the
     * `fixed` events the source lists. Null when no fix above the installed version is known.
     */
    public function fixedVersionFor(Ecosystem $ecosystem, string $name, string $version): ?string
    {
        $comparable = fn (string $value): string => ltrim($value, 'vV');
        $fixedVersions = array_filter((array) ($this->fixed_versions["{$ecosystem->osvName()}:{$name}"] ?? []), is_string(...));

        return collect($fixedVersions)
            ->filter(fn (string $fixed): bool => version_compare($comparable($fixed), $comparable($version), '>'))
            ->sort(fn (string $a, string $b): int => version_compare($comparable($a), $comparable($b)))
            ->first();
    }
}
