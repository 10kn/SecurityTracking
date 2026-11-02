<?php

namespace App\Models;

use App\Enums\Technology;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * A project of a team. An admin can pause its monitoring ("Ngừng theo dõi") once it is no longer maintained: its
 * services are still scanned, but nothing tells the team about them until it is resumed (see PauseProjectMonitoring
 * and ResumeProjectMonitoring).
 */
#[Fillable(['team_id', 'name', 'slug', 'monitoring_paused_at', 'monitoring_paused_by', 'monitoring_pause_reason'])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'monitoring_paused_at' => 'datetime',
        ];
    }

    public function isPaused(): bool
    {
        return $this->monitoring_paused_at !== null;
    }

    /**
     * The technologies its services run on current deployments, whose IncidentLock pausing and resuming it take.
     *
     * @return Collection<int, Technology>
     */
    public function currentTechnologies(): Collection
    {
        return Runtime::whereHas('deployment', fn ($query) => $query->where('is_current', true)->whereHas('service', fn ($query) => $query->where('project_id', $this->id)))
            ->distinct()
            ->pluck('technology')
            ->values();
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return HasMany<Service, $this>
     */
    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function pausedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'monitoring_paused_by');
    }

    /**
     * @return HasMany<ProjectEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(ProjectEvent::class);
    }
}
