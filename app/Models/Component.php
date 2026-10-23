<?php

namespace App\Models;

use App\Enums\Ecosystem;
use Database\Factories\ComponentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['deployment_id', 'ecosystem', 'name', 'version', 'purl', 'lockfile_path', 'is_dev'])]
class Component extends Model
{
    /** @use HasFactory<ComponentFactory> */
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
            'is_dev' => 'boolean',
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
