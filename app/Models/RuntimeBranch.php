<?php

namespace App\Models;

use Database\Factories\RuntimeBranchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['technology_id', 'branch', 'latest_version', 'released_at', 'active_support_until', 'security_support_until', 'is_eol', 'synced_at'])]
class RuntimeBranch extends Model
{
    /** @use HasFactory<RuntimeBranchFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active_support_until' => 'date',
            'released_at' => 'date',
            'security_support_until' => 'date',
            'is_eol' => 'boolean',
            'synced_at' => 'datetime',
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
