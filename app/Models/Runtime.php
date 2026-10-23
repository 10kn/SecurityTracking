<?php

namespace App\Models;

use App\Enums\Language;
use App\Enums\RuntimeSource;
use App\Enums\RuntimeStatus;
use Database\Factories\RuntimeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['deployment_id', 'asset_id', 'language', 'raw_version', 'version', 'source', 'status', 'status_reason', 'observed_at', 'evaluated_at'])]
class Runtime extends Model
{
    /** @use HasFactory<RuntimeFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'language' => Language::class,
            'source' => RuntimeSource::class,
            'status' => RuntimeStatus::class,
            'observed_at' => 'datetime',
            'evaluated_at' => 'datetime',
        ];
    }

    /**
     * E.g. "PHP 8.3.12", using the version as the admin entered it.
     */
    public function displayName(): string
    {
        return $this->language->getLabel().' '.$this->raw_version;
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
