<?php

namespace App\Models;

use App\Enums\CatalogStatus;
use App\Enums\ReleaseSource;
use Database\Factories\TechnologyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A runtime or product whose versions are tracked, e.g. PHP, Node.js, Python or MySQL. Admins add them in the panel.
 */
#[Fillable(['name', 'slug', 'endoflife_product', 'release_source'])]
class Technology extends Model
{
    /** @use HasFactory<TechnologyFactory> */
    use HasFactory;

    /**
     * endoflife.date product ids, e.g. "php", "nodejs", "amazon-linux", "dotnet".
     */
    public const string ENDOFLIFE_PRODUCT_PATTERN = '/^[a-z0-9][a-z0-9.+-]*$/';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'release_source' => ReleaseSource::class,
        ];
    }

    /**
     * Key of this technology's catalog in source_heartbeats.
     */
    public function catalogSourceKey(): string
    {
        return "catalog:{$this->getKey()}";
    }

    public function catalogHeartbeat(): ?SourceHeartbeat
    {
        return SourceHeartbeat::firstWhere('source', $this->catalogSourceKey());
    }

    public function catalogStatus(): CatalogStatus
    {
        $heartbeat = $this->catalogHeartbeat();

        return match (true) {
            $heartbeat?->last_success_at === null => CatalogStatus::NeverSynced,
            $heartbeat->isStale() => CatalogStatus::Stale,
            $heartbeat->last_failure_at?->isAfter($heartbeat->last_success_at) === true => CatalogStatus::LastSyncFailed,
            default => CatalogStatus::Synced,
        };
    }

    /**
     * Versions offered while an admin types, newest first: every known release, end-of-life branches included because
     * legacy services still run them, optionally only those starting with the typed text, e.g. "8.3".
     *
     * @return list<string>
     */
    public function versionSuggestions(?string $startingWith = null): array
    {
        return $this->releases()
            ->pluck('version')
            ->when(filled($startingWith), fn ($versions) => $versions->filter(fn (string $version): bool => str_starts_with($version, $startingWith)))
            ->sort(fn (string $a, string $b): int => version_compare($b, $a))
            ->values()
            ->all();
    }

    /**
     * @return HasMany<RuntimeBranch, $this>
     */
    public function branches(): HasMany
    {
        return $this->hasMany(RuntimeBranch::class);
    }

    /**
     * @return HasMany<RuntimeRelease, $this>
     */
    public function releases(): HasMany
    {
        return $this->hasMany(RuntimeRelease::class);
    }

    /**
     * @return HasMany<Runtime, $this>
     */
    public function runtimes(): HasMany
    {
        return $this->hasMany(Runtime::class);
    }
}
