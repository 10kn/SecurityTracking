<?php

namespace App\Models;

use App\Enums\Environment;
use App\Enums\FindingRule;
use App\Enums\FindingState;
use Database\Factories\DeploymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['service_id', 'environment', 'commit_sha', 'image_digest', 'release', 'is_current', 'deployed_at'])]
class Deployment extends Model
{
    /** @use HasFactory<DeploymentFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'environment' => Environment::class,
            'is_current' => 'boolean',
            'deployed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return HasMany<Runtime, $this>
     */
    public function runtimes(): HasMany
    {
        return $this->hasMany(Runtime::class);
    }

    /**
     * @return HasMany<Lockfile, $this>
     */
    public function lockfiles(): HasMany
    {
        return $this->hasMany(Lockfile::class);
    }

    /**
     * @return HasMany<Component, $this>
     */
    public function components(): HasMany
    {
        return $this->hasMany(Component::class);
    }

    /**
     * @return HasMany<Finding, $this>
     */
    public function findings(): HasMany
    {
        return $this->hasMany(Finding::class);
    }

    /**
     * Open findings about vulnerable packages, for the Packages column.
     *
     * @return HasMany<Finding, $this>
     */
    public function openPackageFindings(): HasMany
    {
        return $this->hasMany(Finding::class)
            ->where('state', FindingState::Open)
            ->where('rule', FindingRule::DependencyVulnerable);
    }
}
