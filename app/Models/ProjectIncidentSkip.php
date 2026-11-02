<?php

namespace App\Models;

use App\Services\IncidentTeamExposure;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * What an incident asked of a project's services when an admin resumed the project without telling its team
 * ("Không gửi"): `exposure` is a snapshot of it (see IncidentTeamExposure::toArray()). The project's services are left
 * out of the incident's alerts while the snapshot still covers what the incident asks of them now, the rule that keeps
 * an admin's "Không" (see IncidentTeam::hasValidAssessment()): a new pre-notice, a newly confirmed branch, exploitation
 * or a rise in severity brings them back. So does a fix confirmed since for a branch they run: the team has to upgrade.
 */
#[Fillable(['project_id', 'incident_id', 'exposure', 'user_id'])]
class ProjectIncidentSkip extends Model
{
    const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'exposure' => 'array',
        ];
    }

    /**
     * The runtimes the incident's alerts are about: those of a project skipped for the incident are left out while
     * its snapshot still covers what the incident asks of them.
     *
     * @template TRuntimes of Collection<int, Runtime>
     *
     * @param  Collection<int, Advisory>  $notices  Every pre-notice of the incident.
     * @param  TRuntimes  $runtimes  With `deployment.service`.
     * @param  array<string, array{version: string, notice: Advisory}>  $patches
     * @return TRuntimes
     */
    public static function withoutSkipped(Incident $incident, Collection $notices, Collection $runtimes, array $patches): Collection
    {
        if ($runtimes->isEmpty()) {
            return $runtimes;
        }

        $byProject = $runtimes->groupBy(fn (Runtime $runtime): int => $runtime->deployment->service->project_id);
        $skips = self::where('incident_id', $incident->id)->whereIn('project_id', $byProject->keys()->all())->get();

        $skippedIds = $skips
            ->filter(fn (self $skip): bool => $skip->stillCovers(IncidentTeamExposure::of($incident, $notices, new EloquentCollection($byProject->get($skip->project_id)->all()), $patches)))
            ->pluck('project_id')
            ->all();

        return $skippedIds === [] ? $runtimes : $runtimes->reject(fn (Runtime $runtime): bool => in_array($runtime->deployment->service->project_id, $skippedIds, true))->values();
    }

    /**
     * Whether the snapshot still covers what the incident asks of the project's services, with no fix confirmed since
     * for a branch they run.
     */
    public function stillCovers(IncidentTeamExposure $exposure): bool
    {
        if (! $exposure->isCoveredBy($this->exposure)) {
            return false;
        }

        $shownPatches = collect($this->exposure['services'])->mapWithKeys(fn (array $service): array => ["{$service['service_id']}|{$service['branch']}" => $service['patch'] ?? null]);

        return collect($exposure->toArray()['services'])->every(fn (array $service): bool => $service['patch'] === null || $shownPatches->get("{$service['service_id']}|{$service['branch']}") === $service['patch']);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Incident, $this>
     */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }
}
