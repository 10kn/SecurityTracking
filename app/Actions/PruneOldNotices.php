<?php

namespace App\Actions;

use App\Enums\AdvisoryKind;
use App\Enums\Technology;
use App\Models\Advisory;
use App\Models\Incident;
use App\Support\IncidentLock;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Deletes the vendor news stored before the collection window (see `psm.notices.collect_days`), e.g. the history a
 * feed listed before the window existed: an incident whose every pre-notice, dismissed or not, was published before
 * the cutoff goes with its pre-notices, and so does such a pre-notice in no incident.
 *
 * Anything that left a trace is kept, as deleting it would rewrite what teams were told: an incident a team was told
 * about, assessed or left out on resuming a project, one another incident was merged into, and a pre-notice sent to a
 * team or to the admin news space or that a finding stands for. A pre-notice without a date is never old.
 */
class PruneOldNotices
{
    /**
     * @return array{incidents: int, notices: int, kept: int} What was deleted, or would be when `$pretend`, and the
     *                                                        incidents kept for their trace.
     */
    public function handle(Technology $technology, CarbonInterface $before, bool $pretend = false): array
    {
        return DB::transaction(function () use ($technology, $before, $pretend): array {
            IncidentLock::take([$technology]);

            $old = Incident::query()
                ->where('technology', $technology)
                ->whereNull('merged_into_id')
                ->whereHas('notices')
                ->whereDoesntHave('notices', fn (Builder $query) => $query->whereNull('published_at')->orWhere('published_at', '>=', $before));
            $incidentIds = (clone $old)
                ->whereNotExists(fn (QueryBuilder $query) => $query->from('incident_team')->whereColumn('incident_team.incident_id', 'incidents.id'))
                ->whereNotExists(fn (QueryBuilder $query) => $query->from('project_incident_skips')->whereColumn('project_incident_skips.incident_id', 'incidents.id'))
                ->whereNotExists(fn (QueryBuilder $query) => $query->from('incidents as merged')->whereColumn('merged.merged_into_id', 'incidents.id'))
                ->whereDoesntHave('notices', fn (Builder $query) => $this->leftATrace($query))
                ->pluck('id');
            $kept = (clone $old)->count() - $incidentIds->count();

            $notices = Advisory::query()
                ->where('kind', AdvisoryKind::PreNotice)
                ->where('source', $technology->noticeSourceKey())
                ->where(fn (Builder $query) => $query
                    ->whereIn('incident_id', $incidentIds)
                    ->orWhere(fn (Builder $query) => $query
                        ->whereNull('incident_id')
                        ->where('published_at', '<', $before)
                        ->whereNot(fn (Builder $query) => $this->leftATrace($query))));
            $noticeCount = (clone $notices)->count();

            if (! $pretend) {
                $notices->delete();
                Incident::whereIn('id', $incidentIds)->delete();
            }

            return ['incidents' => $incidentIds->count(), 'notices' => $noticeCount, 'kept' => $kept];
        });
    }

    /**
     * Pre-notices sent to a team, in an alert or a patch card, or to the admin news space, or that a finding stands for.
     *
     * @param  Builder<Advisory>  $query
     * @return Builder<Advisory>
     */
    private function leftATrace(Builder $query): Builder
    {
        return $query->where(fn (Builder $query) => $query
            ->whereNotNull('news_notification_id')
            ->orWhereExists(fn (QueryBuilder $query) => $query->from('incident_team_notices')->whereColumn('incident_team_notices.advisory_id', 'advisories.id'))
            ->orWhereExists(fn (QueryBuilder $query) => $query->from('incident_team_patches')->whereColumn('incident_team_patches.advisory_id', 'advisories.id'))
            ->orWhereExists(fn (QueryBuilder $query) => $query->from('findings')->whereColumn('findings.advisory_id', 'advisories.id')));
    }
}
