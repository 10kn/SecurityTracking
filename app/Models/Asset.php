<?php

namespace App\Models;

use App\Enums\AssetKind;
use Database\Factories\AssetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['identifier', 'kind', 'hostname', 'last_seen_at'])]
#[Hidden(['token_hash'])]
class Asset extends Model
{
    /** @use HasFactory<AssetFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => AssetKind::class,
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Runtime, $this>
     */
    public function runtimes(): HasMany
    {
        return $this->hasMany(Runtime::class);
    }

    /**
     * @return HasMany<AssetReport, $this>
     */
    public function reports(): HasMany
    {
        return $this->hasMany(AssetReport::class);
    }
}
