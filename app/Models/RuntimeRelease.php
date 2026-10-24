<?php

namespace App\Models;

use Database\Factories\RuntimeReleaseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['technology_id', 'branch', 'version', 'is_security_release', 'released_at'])]
class RuntimeRelease extends Model
{
    /** @use HasFactory<RuntimeReleaseFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_security_release' => 'boolean',
            'released_at' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Technology, $this>
     */
    public function technology(): BelongsTo
    {
        return $this->belongsTo(Technology::class);
    }
}
