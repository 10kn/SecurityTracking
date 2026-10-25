<?php

namespace App\Models;

use App\Enums\Technology;
use Database\Factories\RuntimeReleaseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['technology', 'branch', 'version', 'is_security_release', 'released_at'])]
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
            'technology' => Technology::class,
            'is_security_release' => 'boolean',
            'released_at' => 'date',
        ];
    }
}
