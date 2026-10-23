<?php

namespace App\Models;

use App\Enums\Environment;
use App\Enums\Exposure;
use App\Enums\ServiceKind;
use Database\Factories\ServiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['project_id', 'name', 'slug', 'kind', 'exposure', 'repository_path'])]
class Service extends Model
{
    /** @use HasFactory<ServiceFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ServiceKind::class,
            'exposure' => Exposure::class,
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return HasMany<Deployment, $this>
     */
    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }

    /**
     * The environment admins edit by hand in the MVP.
     *
     * @return HasOne<Deployment, $this>
     */
    public function productionDeployment(): HasOne
    {
        return $this->hasOne(Deployment::class)
            ->where('environment', Environment::Production)
            ->where('is_current', true);
    }
}
