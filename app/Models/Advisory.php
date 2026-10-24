<?php

namespace App\Models;

use App\Enums\AdvisoryKind;
use App\Enums\Ecosystem;
use App\Enums\Severity;
use Database\Factories\AdvisoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['source', 'external_id', 'kind', 'aliases', 'title', 'summary', 'severity', 'cvss_score', 'technology_id', 'url', 'published_at', 'source_modified_at', 'withdrawn_at', 'fixed_versions'])]
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
            'published_at' => 'datetime',
            'source_modified_at' => 'datetime',
            'withdrawn_at' => 'datetime',
            'fixed_versions' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Technology, $this>
     */
    public function technology(): BelongsTo
    {
        return $this->belongsTo(Technology::class);
    }

    /**
     * @return HasMany<Finding, $this>
     */
    public function findings(): HasMany
    {
        return $this->hasMany(Finding::class);
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
