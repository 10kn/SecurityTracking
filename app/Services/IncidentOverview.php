<?php

namespace App\Services;

use App\Enums\IncidentImpact;
use App\Enums\IncidentSuggestionStatus;
use App\Enums\Severity;
use App\Enums\Technology;
use App\Models\Advisory;
use App\Models\Incident;
use App\Models\IncidentSuggestion;
use App\Models\IncidentTeam;
use App\Models\IncidentTeamNotice;
use App\Models\Runtime;
use App\Models\Team;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * What the admin incident pages show of incidents: their pre-notices, the fix of each branch, the pending suggestions,
 * and each team that was told about them or runs their technology, with its exposure and assessment. An incident
 * needs an admin when it has a pending suggestion, or a team is reminded of it every day (see the `needs_acknowledgement`
 * of a team): the same rule as the reminders of the daily digest (see SendDailyDigest::awaitingPatch()).
 *
 * Everything is read for a set of incidents at once, in a number of queries that does not grow with the incidents or
 * the teams, so a list page costs the same whatever it shows.
 *
 * @phpstan-type TeamState array{team: Team, runtimes: EloquentCollection<int, Runtime>, row: IncidentTeam|null, exposure: IncidentTeamExposure, told_severity: Severity|null, is_told: bool, is_running: bool, is_actionable: bool, is_assessed: bool, waiting_branches: list<string>, needs_acknowledgement: bool}
 * @phpstan-type IncidentState array{incident: Incident, notices: EloquentCollection<int, Advisory>, fixes: list<array{branch: string, version: string|null}>, pending_suggestions_count: int, teams: Collection<int, TeamState>}
 */
class IncidentOverview
{
    public function __construct(private AlertPolicy $policy) {}

    /**
     * The state of each incident, by incident id. With `$withHistory`, the teams' rows come with their events and
     * opening messages, for the incident page.
     *
     * @param  Collection<int, Incident>  $incidents
     * @return Collection<int, IncidentState>
     */
    public function states(Collection $incidents, bool $withHistory = false): Collection
    {
        if ($incidents->isEmpty()) {
            return collect();
        }

        $incidentIds = $incidents->map(fn (Incident $incident): int => $incident->id)->values()->all();
        $notices = Advisory::whereIn('incident_id', $incidentIds)->orderBy('published_at')->orderBy('id')->get()->groupBy('incident_id');
        $noticeIds = $notices->collapse()->pluck('id')->all();
        $technologies = $incidents->map(fn (Incident $incident): Technology => $incident->technology)->unique(fn (Technology $technology): string => $technology->value);
        $runtimes = $technologies->mapWithKeys(fn (Technology $technology): array => [$technology->value => $this->runtimesOf($technology)]);
        $releases = $technologies->mapWithKeys(fn (Technology $technology): array => [$technology->value => Incident::securityReleases(
            $technology,
            $notices->collapse()->where('technology', $technology)->flatMap(fn (Advisory $notice): array => $notice->patch_versions ?? [])->unique()->values()->all(),
        )]);
        $ledger = IncidentTeamNotice::ledgerFor($noticeIds);
        $rows = IncidentTeam::whereIn('incident_id', $incidentIds)
            ->with($withHistory ? ['acknowledgedBy', 'openingNotification', 'events.user'] : ['acknowledgedBy', 'openingNotification'])
            ->get()
            ->groupBy('incident_id');
        $suggestions = $this->pendingSuggestionsQuery($incidentIds, $noticeIds)->get(['id', 'incident_id', 'advisory_id']);
        $incidentOfNotice = $notices->collapse()->pluck('incident_id', 'id');
        $teams = Team::whereKey($ledger->pluck('team_id')->merge($runtimes->flatMap(fn (EloquentCollection $runtimes): array => $runtimes->map(fn (Runtime $runtime): int => $runtime->deployment->service->project->team_id)->all()))->unique()->values())
            ->orderBy('name')
            ->get();

        return $incidents->mapWithKeys(function (Incident $incident) use ($notices, $runtimes, $releases, $ledger, $rows, $suggestions, $incidentOfNotice, $teams): array {
            $incidentNotices = $notices->get($incident->id) ?? new EloquentCollection;
            $patches = $incident->confirmedPatches($incidentNotices, $releases->get($incident->technology->value));
            $technologyRuntimes = $runtimes->get($incident->technology->value) ?? new EloquentCollection;
            $memberIds = $incidentNotices->modelKeys();

            return [$incident->id => [
                'incident' => $incident,
                'notices' => $incidentNotices,
                'fixes' => $this->fixes($incident, $technologyRuntimes, $patches),
                'pending_suggestions_count' => $suggestions->filter(fn (IncidentSuggestion $suggestion): bool => $suggestion->incident_id === $incident->id || (int) $incidentOfNotice->get($suggestion->advisory_id) === $incident->id)->count(),
                'teams' => $this->teams($incident, $incidentNotices, $patches, $technologyRuntimes, $ledger->whereIn('advisory_id', $memberIds), $rows->get($incident->id) ?? new EloquentCollection, $teams),
            ]];
        });
    }

    /**
     * The state of one incident (see states()).
     *
     * @return IncidentState
     */
    public function state(Incident $incident, bool $withHistory = false): array
    {
        return $this->states(collect([$incident]), $withHistory)->get($incident->id);
    }

    /**
     * The pre-notice an incident is listed by: its most severe one that is not dismissed, else its first one.
     *
     * @param  IncidentState  $state
     */
    public function lead(array $state): ?Advisory
    {
        $notices = $state['notices'];

        return $notices->where('is_dismissed', false)->sortByDesc(fn (Advisory $notice): int => $notice->severity->rank())->first() ?? $notices->first();
    }

    /**
     * Whether an admin should look at the incident: it has a pending suggestion, or a team is reminded of it; with a
     * team, only that team counts.
     *
     * @param  IncidentState  $state
     */
    public function needsAttention(array $state, ?int $teamId = null): bool
    {
        return $state['pending_suggestions_count'] > 0
            || $state['teams']->contains(fn (array $team): bool => $team['needs_acknowledgement'] && ($teamId === null || $team['team']->id === $teamId));
    }

    /**
     * The current incidents that may need an admin, narrowed by the database: those with a pending suggestion, and
     * those recent enough for a team to be reminded of them (see isWithinReminderWindow()).
     *
     * @param  Builder<Incident>  $query
     * @return Builder<Incident>
     */
    public function mayNeedAttention(Builder $query): Builder
    {
        $pending = IncidentSuggestion::where('status', IncidentSuggestionStatus::Pending);

        return $query->where(fn (Builder $query) => $query
            ->whereIn('id', (clone $pending)->select('incident_id'))
            ->orWhereIn('id', Advisory::whereIn('id', (clone $pending)->select('advisory_id'))->whereNotNull('incident_id')->select('incident_id'))
            ->orWhere('first_published_at', '>=', now()->subDays(config('psm.incidents.awaiting_patch_days'))));
    }

    /**
     * Whether an admin marked the incident as read with "Chưa rõ" for a team told about it or running its technology,
     * and the assessment still holds (see IncidentTeam::hasValidAssessment()); with a team, only that team counts.
     * Unlike needsAttention(), it does not depend on how recent the incident is, nor stop being true once the daily
     * reminder stopped.
     *
     * @param  IncidentState  $state
     */
    public function hasUnknownImpact(array $state, ?int $teamId = null): bool
    {
        return $state['teams']->contains(fn (array $team): bool => $team['is_assessed']
            && $team['row']?->impact === IncidentImpact::Unknown
            && ($teamId === null || $team['team']->id === $teamId));
    }

    /**
     * The incidents an admin marked as read with "Chưa rõ" for a team, for that team only when there is one, narrowed
     * by the database; whether the assessment still holds is read by hasUnknownImpact().
     *
     * @param  Builder<Incident>  $query
     * @return Builder<Incident>
     */
    public function mayHaveUnknownImpact(Builder $query, ?int $teamId = null): Builder
    {
        return $query->whereIn('id', IncidentTeam::where('impact', IncidentImpact::Unknown)
            ->when($teamId !== null, fn (Builder $query) => $query->where('team_id', $teamId))
            ->select('incident_id'));
    }

    /**
     * The pending suggestions about the incident: a pre-notice that may belong to it, or one of its pre-notices that
     * may belong to another incident.
     *
     * @return Builder<IncidentSuggestion>
     */
    public function pendingSuggestions(Incident $incident): Builder
    {
        return $this->pendingSuggestionsQuery([$incident->id], $incident->notices()->pluck('id')->all());
    }

    /**
     * The incident a merged one went into, followed to the end.
     */
    public function canonical(Incident $incident): Incident
    {
        for ($hops = 0; $incident->merged_into_id !== null && $hops < 10; $hops++) {
            $next = Incident::find($incident->merged_into_id);

            if ($next === null) {
                break;
            }

            $incident = $next;
        }

        return $incident;
    }

    /**
     * The teams told about the incident or running its technology, by name.
     *
     * @param  EloquentCollection<int, Advisory>  $notices
     * @param  array<string, array{version: string, notice: Advisory}>  $patches
     * @param  EloquentCollection<int, Runtime>  $runtimes  The runtimes of the technology, of every team.
     * @param  Collection<int, array{team_id: int, advisory_id: int, severity: Severity, is_sent: bool}>  $ledger  What the teams were told of the incident's pre-notices.
     * @param  EloquentCollection<int, IncidentTeam>  $rows  The incident's rows.
     * @param  EloquentCollection<int, Team>  $teams  Every team any of the incidents read concerns, by name.
     * @return Collection<int, TeamState>
     */
    private function teams(Incident $incident, EloquentCollection $notices, array $patches, EloquentCollection $runtimes, Collection $ledger, EloquentCollection $rows, EloquentCollection $teams): Collection
    {
        $runtimesByTeam = $runtimes->groupBy(fn (Runtime $runtime): int => $runtime->deployment->service->project->team_id);
        $toldTeamIds = $ledger->where('is_sent', true)->pluck('team_id')->unique();
        $rowsByTeam = $rows->keyBy('team_id');
        $isWithinWindow = $this->isWithinReminderWindow($incident);

        return $teams
            ->filter(fn (Team $team): bool => $toldTeamIds->contains($team->id) || $runtimesByTeam->has($team->id))
            ->map(function (Team $team) use ($incident, $notices, $patches, $runtimesByTeam, $ledger, $toldTeamIds, $rowsByTeam, $isWithinWindow): array {
                $teamRuntimes = $runtimesByTeam->get($team->id) ?? new EloquentCollection;
                $exposure = IncidentTeamExposure::of($incident, $notices, $teamRuntimes, $patches);
                $row = $rowsByTeam->get($team->id);
                $isTold = $toldTeamIds->contains($team->id);
                $toldSeverity = $isTold ? $ledger->where('team_id', $team->id)->map(fn (array $entry): Severity => $entry['severity'])->sortByDesc(fn (Severity $severity): int => $severity->rank())->first() : null;
                $isAssessed = $row?->hasValidAssessment($exposure) ?? false;
                $waiting = IncidentTeamExposure::waitingBranches($incident, $teamRuntimes, $patches);
                $isActionable = $isTold && $teamRuntimes->isNotEmpty();

                return [
                    'team' => $team,
                    'runtimes' => $teamRuntimes,
                    'row' => $row,
                    'exposure' => $exposure,
                    'told_severity' => $toldSeverity,
                    'is_told' => $isTold,
                    'is_running' => $teamRuntimes->isNotEmpty(),
                    'is_actionable' => $isActionable,
                    'is_assessed' => $isAssessed,
                    'waiting_branches' => $waiting,
                    'needs_acknowledgement' => $isActionable && ! $isAssessed && $waiting !== [] && $isWithinWindow && $this->policy->remindsOfIncident($toldSeverity),
                ];
            })
            ->values();
    }

    /**
     * The fix of each branch the incident concerns: the branches the vendor confirmed a fix for, and those any team
     * runs that the vendor did not rule out, naturally sorted. `version` is null while a branch waits.
     *
     * @param  EloquentCollection<int, Runtime>  $runtimes
     * @param  array<string, array{version: string, notice: Advisory}>  $patches
     * @return list<array{branch: string, version: string|null}>
     */
    private function fixes(Incident $incident, EloquentCollection $runtimes, array $patches): array
    {
        $fixes = [];

        foreach ($patches as $branch => $patch) {
            $fixes[] = ['branch' => (string) $branch, 'version' => $patch['version']];
        }

        foreach (IncidentTeamExposure::waitingBranches($incident, $runtimes, $patches) as $branch) {
            $fixes[] = ['branch' => $branch, 'version' => null];
        }

        usort($fixes, fn (array $a, array $b): int => strnatcmp($a['branch'], $b['branch']));

        return $fixes;
    }

    /**
     * @param  list<int>  $incidentIds
     * @param  list<int>  $noticeIds  The incidents' pre-notices.
     * @return Builder<IncidentSuggestion>
     */
    private function pendingSuggestionsQuery(array $incidentIds, array $noticeIds): Builder
    {
        return IncidentSuggestion::where('status', IncidentSuggestionStatus::Pending)
            ->where(fn (Builder $query) => $query->whereIn('incident_id', $incidentIds)->orWhereIn('advisory_id', $noticeIds));
    }

    /**
     * Teams are reminded of incidents of the last `psm.incidents.awaiting_patch_days` only, as in the digest.
     */
    private function isWithinReminderWindow(Incident $incident): bool
    {
        return $incident->first_published_at !== null && $incident->first_published_at->gte(now()->subDays(config('psm.incidents.awaiting_patch_days')));
    }

    /**
     * The runtimes of the technology on current deployments, of every team.
     *
     * @return EloquentCollection<int, Runtime>
     */
    private function runtimesOf(Technology $technology): EloquentCollection
    {
        return Runtime::where('technology', $technology)
            ->whereHas('deployment', fn ($query) => $query->where('is_current', true))
            ->with('deployment.service.project')
            ->orderBy('id')
            ->get();
    }
}
