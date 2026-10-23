<?php

namespace App\Models;

use App\Enums\AdvisoryKind;
use App\Enums\Language;
use App\Enums\Severity;
use Database\Factories\AdvisoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['source', 'external_id', 'kind', 'aliases', 'title', 'summary', 'severity', 'cvss_score', 'language', 'url', 'published_at'])]
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
            'language' => Language::class,
            'published_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Finding, $this>
     */
    public function findings(): HasMany
    {
        return $this->hasMany(Finding::class);
    }
}
