<?php

namespace App\Models;

use App\Enums\Language;
use Database\Factories\RuntimeReleaseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['language', 'branch', 'version', 'is_security_release', 'released_at'])]
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
            'language' => Language::class,
            'is_security_release' => 'boolean',
            'released_at' => 'date',
        ];
    }
}
