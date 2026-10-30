<?php

namespace App\Services;

use App\Enums\BranchSupport;
use App\Enums\IncidentImpact;
use App\Enums\IncidentSuggestionStatus;
use App\Enums\Severity;
use App\Enums\Technology;
use App\Models\Advisory;
use App\Models\ChatNotification;
use App\Models\Incident;
use App\Models\IncidentSuggestion;
use App\Models\IncidentTeam;
use App\Models\IncidentTeamNotice;
use App\Models\Runtime;
use App\Models\RuntimeBranch;
use App\Models\Team;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What the admin incident pages show of incidents: their pre-notices, the fix of each branch, the pending suggestions,
 * and each team that was told about them or runs their technology, with its exposure and assessment. An incident
 * needs an admin when it has a pending suggestion, or a team is reminded of it every day (see the `needs_acknowledgement`
 * of a team): the same rule as the reminders of the daily digest (see SendDailyDigest::awaitingPatch()).
 *
 * The fix of a branch, whether the catalog says the branch reached end of life and whether the incident is recent enough
 * for reminders are kept as separate facts: none of them replaces another.
 *
 * A team counts as told once a card about the incident was queued for it (`is_told`). Whether Google Chat accepted it is
 * read apart, from the messages of the team's current thread (`delivery`): the thread key and generation of its row,
 * so a thread taken over in a merge counts and one left behind by a webhook change does not, also before the next
 * message moves the row (`is_webhook_changed`). A message that failed and was not sent again stays a failure, whatever
 * was delivered after it.
 *
 * Everything is read for a set of incidents at once, in a number of queries that does not grow with the incidents or
 * the teams, so a list page costs the same whatever it shows.
 *
 * @phpstan-type ThreadDelivery array{has_messages: bool, last_sent_at: Carbon|null, failures_count: int, pending_count: int, opener: ChatNotification|null, latest: ChatNotification|null, failures: Collection<int, ChatNotification>}
 * @phpstan-type Delivery array{has_messages: bool, last_sent_at: Carbon|null, failures_count: int, pending_count: int, opener: ChatNotification|null, latest: ChatNotification|null, failures: Collection<int, ChatNotification>, is_legacy: bool, is_webhook_changed: bool}
 * @phpstan-type TeamState array{team: Team, runtimes: EloquentCollection<int, Runtime>, row: IncidentTeam|null, exposure: IncidentTeamExposure, told_severity: Severity|null, is_told: bool, is_running: bool, is_actionable: bool, is_assessed: bool, waiting_branches: list<string>, needs_acknowledgement: bool, delivery: Delivery}
 * @phpstan-type IncidentState array{incident: Incident, notices: EloquentCollection<int, Advisory>, fixes: list<array{branch: string, version: string|null, is_eol: bool}>, is_within_reminder_window: bool, pending_suggestions_count: int, teams: Collection<int, TeamState>}
 */
class IncidentOverview
{
    /**
     * The delivery of a team whose current thread has no message.
     */
    private const array NO_DELIVERY = ['has_messages' => false, 'last_sent_at' => null, 'failures_count' => 0, 'pending_count' => 0, 'opener' => null, 'latest' => null];

    /**
     * The failures of a thread the incident page lists, oldest first.
     */
    public const int FAILURES_SHOWN = 10;

    public function __construct(private AlertPolicy $policy) {}

    /**
     * The state of each incident, by incident id. With `$withHistory`, the teams' rows come with their events, and their
     * deliveries with the latest message and the failures, for the incident page.
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
        $eolBranches = $this->eolBranchesOf($technologies);
        $ledger = IncidentTeamNotice::ledgerFor($noticeIds);
        $rows = IncidentTeam::whereIn('incident_id', $incidentIds)
            ->with($withHistory ? ['acknowledgedBy', 'openingNotification', 'events.user'] : ['acknowledgedBy', 'openingNotification'])
            ->get()
            ->groupBy('incident_id');
        $deliveries = $this->deliveriesOf($rows->collapse(), $withHistory);
        $suggestions = $this->pendingSuggestionsQuery($incidentIds, $noticeIds)->get(['id', 'incident_id', 'advisory_id']);
        $incidentOfNotice = $notices->collapse()->pluck('incident_id', 'id');
        $teams = Team::whereKey($ledger->pluck('team_id')->merge($runtimes->flatMap(fn (EloquentCollection $runtimes): array => $runtimes->map(fn (Runtime $runtime): int => $runtime->deployment->service->project->team_id)->all()))->unique()->values())
            ->orderBy('name')
            ->get();

        return $incidents->mapWithKeys(function (Incident $incident) use ($notices, $runtimes, $releases, $eolBranches, $ledger, $rows, $deliveries, $suggestions, $incidentOfNotice, $teams): array {
            $incidentNotices = $notices->get($incident->id) ?? new EloquentCollection;
            $patches = $incident->confirmedPatches($incidentNotices, $releases->get($incident->technology->value));
            $technologyRuntimes = $runtimes->get($incident->technology->value) ?? new EloquentCollection;
            $memberIds = $incidentNotices->modelKeys();

            return [$incident->id => [
                'incident' => $incident,
                'notices' => $incidentNotices,
                'fixes' => $this->fixes($incident, $technologyRuntimes, $patches, $eolBranches->get($incident->technology->value) ?? []),
                'is_within_reminder_window' => $this->isWithinReminderWindow($incident),
                'pending_suggestions_count' => $suggestions->filter(fn (IncidentSuggestion $suggestion): bool => $suggestion->incident_id === $incident->id || (int) $incidentOfNotice->get($suggestion->advisory_id) === $incident->id)->count(),
                'teams' => $this->teams($incident, $incidentNotices, $patches, $technologyRuntimes, $ledger->whereIn('advisory_id', $memberIds), $rows->get($incident->id) ?? new EloquentCollection, $deliveries, $teams),
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
     * @param  Collection<int, array{team_id: int, advisory_id: int, severity: Severity, is_sent: bool, is_legacy: bool}>  $ledger  What the teams were told of the incident's pre-notices.
     * @param  EloquentCollection<int, IncidentTeam>  $rows  The incident's rows.
     * @param  Collection<int, ThreadDelivery>  $deliveries  The delivery of each row's current thread, by row id.
     * @param  EloquentCollection<int, Team>  $teams  Every team any of the incidents read concerns, by name.
     * @return Collection<int, TeamState>
     */
    private function teams(Incident $incident, EloquentCollection $notices, array $patches, EloquentCollection $runtimes, Collection $ledger, EloquentCollection $rows, Collection $deliveries, EloquentCollection $teams): Collection
    {
        $runtimesByTeam = $runtimes->groupBy(fn (Runtime $runtime): int => $runtime->deployment->service->project->team_id);
        $toldTeamIds = $ledger->where('is_sent', true)->pluck('team_id')->unique();
        $rowsByTeam = $rows->keyBy('team_id');
        $isWithinWindow = $this->isWithinReminderWindow($incident);

        return $teams
            ->filter(fn (Team $team): bool => $toldTeamIds->contains($team->id) || $runtimesByTeam->has($team->id))
            ->map(function (Team $team) use ($incident, $notices, $patches, $runtimesByTeam, $ledger, $toldTeamIds, $rowsByTeam, $deliveries, $isWithinWindow): array {
                $teamRuntimes = $runtimesByTeam->get($team->id) ?? new EloquentCollection;
                $exposure = IncidentTeamExposure::of($incident, $notices, $teamRuntimes, $patches);
                $row = $rowsByTeam->get($team->id);
                $isWebhookChanged = $row?->willMoveTo($team->google_chat_webhook_url) ?? false;
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
                    'delivery' => [
                        ...self::NO_DELIVERY,
                        'failures' => collect(),
                        ...($row === null || $isWebhookChanged ? [] : $deliveries->get($row->id) ?? []),
                        'is_legacy' => $ledger->where('team_id', $team->id)->contains('is_legacy', true),
                        'is_webhook_changed' => $isWebhookChanged,
                    ],
                ];
            })
            ->values();
    }

    /**
     * The fix of each branch the incident concerns: the branches the vendor confirmed a fix for, and those any team
     * runs that the vendor did not rule out, naturally sorted. `version` is null while a branch waits. `is_eol` when the
     * catalog says the branch reached end of life; a branch the catalog does not list is not taken for one.
     *
     * @param  EloquentCollection<int, Runtime>  $runtimes
     * @param  array<string, array{version: string, notice: Advisory}>  $patches
     * @param  list<string>  $eolBranches  The branches of the technology the catalog says reached end of life.
     * @return list<array{branch: string, version: string|null, is_eol: bool}>
     */
    private function fixes(Incident $incident, EloquentCollection $runtimes, array $patches, array $eolBranches): array
    {
        $fixes = [];

        foreach ($patches as $branch => $patch) {
            $fixes[] = ['branch' => (string) $branch, 'version' => $patch['version'], 'is_eol' => in_array((string) $branch, $eolBranches, true)];
        }

        foreach (IncidentTeamExposure::waitingBranches($incident, $runtimes, $patches) as $branch) {
            $fixes[] = ['branch' => $branch, 'version' => null, 'is_eol' => in_array($branch, $eolBranches, true)];
        }

        usort($fixes, fn (array $a, array $b): int => strnatcmp($a['branch'], $b['branch']));

        return $fixes;
    }

    /**
     * The delivery of each row's current thread, by row id: whether it has any message, when Google Chat last accepted
     * one, how many failed and were not sent again and how many wait to be sent (one sent again included). With
     * `$withDetails`, also the opener, the latest message and the first FAILURES_SHOWN failures. Rows whose thread has
     * no message are left out.
     *
     * @param  Collection<int, IncidentTeam>  $rows
     * @return Collection<int, ThreadDelivery>
     */
    private function deliveriesOf(Collection $rows, bool $withDetails): Collection
    {
        $threadKeys = $rows->pluck('thread_key')->unique()->values()->all();

        if ($threadKeys === []) {
            return collect();
        }

        $threads = ChatNotification::whereIn('thread_key', $threadKeys)
            ->groupBy('thread_key', 'generation')
            ->selectRaw('thread_key, generation, max(sent_at) as last_sent_at, max(id) as latest_id, count(*) filter (where failed_at is not null and sent_at is null) as failures_count, count(*) filter (where failed_at is null and sent_at is null) as pending_count')
            ->toBase()
            ->get()
            ->keyBy(fn (object $thread): string => "{$thread->thread_key}#{$thread->generation}");

        $columns = ['id', 'thread_key', 'generation', 'thread_role', 'sent_at', 'failed_at', 'last_error', 'created_at'];
        $latest = $withDetails ? ChatNotification::whereKey($threads->pluck('latest_id')->all())->get($columns)->keyBy('id') : collect();
        $failures = $withDetails ? ChatNotification::fromSub(
            ChatNotification::whereIn('thread_key', $threadKeys)->whereNotNull('failed_at')->whereNull('sent_at')->select($columns)->selectRaw('row_number() over (partition by thread_key, generation order by id) as position'),
            'chat_notifications',
        )->where('position', '<=', self::FAILURES_SHOWN)->orderBy('id')->get() : collect();

        return $rows
            ->filter(fn (IncidentTeam $row): bool => $threads->has("{$row->thread_key}#{$row->generation}"))
            ->mapWithKeys(function (IncidentTeam $row) use ($threads, $latest, $failures): array {
                $thread = $threads->get("{$row->thread_key}#{$row->generation}");
                $isOfThread = fn (?ChatNotification $notification): bool => $notification !== null && $notification->thread_key === $row->thread_key && $notification->generation === $row->generation;

                return [$row->id => [
                    'has_messages' => true,
                    'last_sent_at' => $thread->last_sent_at === null ? null : Carbon::parse($thread->last_sent_at),
                    'failures_count' => (int) $thread->failures_count,
                    'pending_count' => (int) $thread->pending_count,
                    'opener' => $isOfThread($row->openingNotification) ? $row->openingNotification : null,
                    'latest' => $latest->get($thread->latest_id),
                    'failures' => $failures->filter($isOfThread)->values(),
                ]];
            });
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
     * The branches of each technology the catalog says reached end of life, by technology.
     *
     * @param  Collection<int, Technology>  $technologies
     * @return Collection<string, list<string>>
     */
    private function eolBranchesOf(Collection $technologies): Collection
    {
        return RuntimeBranch::whereIn('technology', $technologies->values()->all())
            ->get()
            ->filter(fn (RuntimeBranch $branch): bool => $branch->support() === BranchSupport::Eol)
            ->groupBy(fn (RuntimeBranch $branch): string => $branch->technology->value)
            ->map(fn (Collection $branches): array => $branches->map(fn (RuntimeBranch $branch): string => (string) $branch->branch)->values()->all());
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
