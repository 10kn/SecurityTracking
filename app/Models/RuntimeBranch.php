<?php

namespace App\Models;

use App\Enums\BranchSupport;
use App\Enums\Technology;
use Database\Factories\RuntimeBranchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['technology', 'branch', 'latest_version', 'released_at', 'active_support_until', 'security_support_until', 'is_eol', 'synced_at'])]
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
            'technology' => Technology::class,
            'active_support_until' => 'date',
            'released_at' => 'date',
            'security_support_until' => 'date',
            'is_eol' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }

    /**
     * A branch without an end of active support date (Ruby) counts as active until it reaches end of life.
     */
    public function support(): BranchSupport
    {
        return match (true) {
            $this->is_eol || $this->security_support_until?->isBefore(today()) === true => BranchSupport::Eol,
            $this->active_support_until?->isBefore(today()) === true => BranchSupport::SecurityOnly,
            default => BranchSupport::Active,
        };
    }
}
