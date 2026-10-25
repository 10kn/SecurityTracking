<?php

namespace App\Models;

use App\Enums\RuntimeSource;
use App\Enums\RuntimeStatus;
use App\Enums\Technology;
use Database\Factories\RuntimeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['deployment_id', 'asset_id', 'technology', 'raw_version', 'version', 'branch', 'source', 'status', 'status_reason', 'status_context', 'observed_at', 'evaluated_at'])]
class Runtime extends Model
{
    /** @use HasFactory<RuntimeFactory> */
    use HasFactory;

    /**
     * Version as found on the server, e.g. "8.3.12", "v20.11.0" or "8.3.12-1+deb.sury.org+1".
     */
    public const string RAW_VERSION_PATTERN = '/^[0-9A-Za-z][0-9A-Za-z.+~_-]*$/';

    public const int RAW_VERSION_MAX_LENGTH = 64;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'technology' => Technology::class,
            'source' => RuntimeSource::class,
            'status' => RuntimeStatus::class,
            'status_context' => 'array',
            'observed_at' => 'datetime',
            'evaluated_at' => 'datetime',
        ];
    }

    /**
     * E.g. "PHP 8.3.12", using the version as the admin entered it.
     */
    public function displayName(): string
    {
        return $this->technology->getLabel().' '.$this->raw_version;
    }

    /**
     * @return BelongsTo<Asset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * @return BelongsTo<Deployment, $this>
     */
    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class);
    }
}
