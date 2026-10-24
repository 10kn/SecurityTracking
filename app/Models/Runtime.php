<?php

namespace App\Models;

use App\Enums\RuntimeSource;
use App\Enums\RuntimeStatus;
use Database\Factories\RuntimeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['deployment_id', 'asset_id', 'technology_id', 'raw_version', 'version', 'branch', 'source', 'status', 'status_reason', 'status_context', 'observed_at', 'evaluated_at'])]
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
        return $this->technology->name.' '.$this->raw_version;
    }

    /**
     * Why the runtime has its status, in the viewer's language, e.g. "Đã có bản vá bảo mật 8.3.14".
     */
    public function statusReasonText(): ?string
    {
        return $this->status_reason === null ? null : __($this->status_reason, $this->status_context ?? []);
    }

    /**
     * @return BelongsTo<Technology, $this>
     */
    public function technology(): BelongsTo
    {
        return $this->belongsTo(Technology::class);
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
