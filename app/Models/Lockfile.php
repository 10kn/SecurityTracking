<?php

namespace App\Models;

use App\Enums\Ecosystem;
use Database\Factories\LockfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['deployment_id', 'ecosystem', 'path', 'content', 'content_hash', 'parsed_hash', 'parse_error', 'package_count', 'uploaded_at'])]
class Lockfile extends Model
{
    /** @use HasFactory<LockfileFactory> */
    use HasFactory;

    /**
     * Upload limit per lockfile, in kilobytes. Large npm projects have package-lock.json files of several MB.
     */
    public const int MAX_UPLOAD_KILOBYTES = 20480;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ecosystem' => Ecosystem::class,
            'package_count' => 'integer',
            'uploaded_at' => 'datetime',
        ];
    }

    /**
     * Whether the current content was read into components, successfully or not.
     */
    public function isParsed(): bool
    {
        return $this->parsed_hash !== null && $this->parsed_hash === $this->content_hash;
    }

    /**
     * Whether the current content could not be read. The components of the previous content are kept until it can.
     */
    public function isUnreadable(): bool
    {
        return $this->isParsed() && $this->parse_error !== null;
    }

    /**
     * @return BelongsTo<Deployment, $this>
     */
    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class);
    }
}
