<?php

namespace App\Models;

use App\Enums\Language;
use Database\Factories\RuntimeBranchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['language', 'branch', 'latest_version', 'active_support_until', 'security_support_until', 'synced_at'])]
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
            'language' => Language::class,
            'active_support_until' => 'date',
            'security_support_until' => 'date',
            'synced_at' => 'datetime',
        ];
    }
}
