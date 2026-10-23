<?php

namespace App\Models;

use App\Enums\Ecosystem;
use Database\Factories\LockfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['deployment_id', 'ecosystem', 'path', 'content', 'content_hash', 'uploaded_at'])]
class Lockfile extends Model
{
    /** @use HasFactory<LockfileFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ecosystem' => Ecosystem::class,
            'uploaded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Deployment, $this>
     */
    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class);
    }
}
