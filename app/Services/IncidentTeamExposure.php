<?php

namespace App\Services;

use App\Enums\IncidentScope;
use App\Enums\Severity;
use App\Models\Advisory;
use App\Models\Incident;
use App\Models\Runtime;
use App\Models\Team;
use App\Services\Kev\KnownExploitation;
use Illuminate\Support\Collection;

/**
 * What an incident asks of one team now, computed from the current data and never stored: the incident's pre-notices
 * and their CVE and GHSA ids (which issue it is), the team's services on the technology with the branch they run,
 * whether the vendor said the branch is affected and the fix confirmed for it, the highest severity, whether the
 * incident is exploited and the CVE ids of it CISA KEV lists. The scope of every runtime is the one the alerts use (see Incident::scopeFor()), which reads
 * the branch from the version when the branch is not known; such a runtime gets no fix, as the alerts give it none.
 * Only a runtime whose branch and version say nothing is of unknown scope.
 *
 * An admin's assessment of the incident for the team ("Đã đọc", see IncidentTeam) keeps a snapshot of it, and holds
 * only while the snapshot still covers the current exposure (see isCoveredBy()): nothing it did not show may be asked
 * of the team since. Being computed on the fly, no write path can leave a stale assessment in force.
 */
final readonly class IncidentTeamExposure
{
    /**
     * @param  list<int>  $noticeIds  The incident's pre-notices, sorted.
     * @param  list<array{service_id: int, service: string, branch: string, scope: string, patch: string|null}>  $services  Sorted by service and branch.
     * @param  array<int, list<string>>  $noticeIssueIds  Pre-notice id => its CVE and GHSA ids.
     * @param  list<string>  $knownExploitedCves  Sorted.
     */
    public function __construct(
        public array $noticeIds,
        public array $services,
        public Severity $severity,
        public bool $isExploited,
        public array $noticeIssueIds = [],
        public array $knownExploitedCves = [],
    ) {}

    /**
     * The exposure of the team to the incident, read from the database: its runtimes of the technology on current
     * deployments.
     */
    public static function for(Incident $incident, Team|int $team): self
    {
        $teamId = $team instanceof Team ? $team->id : $team;
        $notices = $incident->notices()->get();

        return self::of($incident, $notices, self::runtimesOf($incident, $teamId), $incident->confirmedPatches($notices));
    }

    /**
     * The team's runtimes of the incident's technology on current deployments, with `deployment.service.project`.
     *
     * @return Collection<int, Runtime>
     */
    public static function runtimesOf(Incident $incident, int $teamId): Collection
    {
        return Runtime::where('technology', $incident->technology)
            ->whereHas('deployment', fn ($query) => $query->where('is_current', true)->whereHas('service.project', fn ($query) => $query->where('team_id', $teamId)))
            ->with('deployment.service.project')
            ->orderBy('id')
            ->get();
    }

    /**
     * The exposure from what the caller already read.
     *
     * @param  Collection<int, Advisory>  $notices  Every pre-notice of the incident, dismissed ones included.
     * @param  Collection<int, Runtime>  $runtimes  The team's runtimes of the technology on current deployments, with `deployment.service`.
     * @param  array<string, array{version: string, notice: Advisory}>  $patches  The incident's confirmed fixes (see Incident::confirmedPatches()).
     */
    public static function of(Incident $incident, Collection $notices, Collection $runtimes, array $patches): self
    {
        $counting = $notices->where('is_dismissed', false);

        $services = $runtimes
            ->map(function (Runtime $runtime) use ($incident, $patches): array {
                $branch = (string) ($runtime->branch ?? $runtime->version ?? $runtime->raw_version);

                return [
                    'service_id' => $runtime->deployment->service_id,
                    'service' => $runtime->deployment->service->name,
                    'branch' => $branch,
                    'scope' => $incident->scopeFor($runtime)->value,
                    'patch' => $runtime->branch === null ? null : $patches[$branch]['version'] ?? null,
                ];
            })
            ->unique(fn (array $service): string => "{$service['service_id']}|{$service['branch']}|{$service['scope']}")
            ->sort(fn (array $a, array $b): int => [$a['service_id'], $a['branch'], $a['scope']] <=> [$b['service_id'], $b['branch'], $b['scope']])
            ->values()
            ->all();

        return new self(
            noticeIds: $notices->pluck('id')->map(fn (mixed $id): int => (int) $id)->sort()->values()->all(),
            services: $services,
            severity: Incident::highestSeverity($counting),
            isExploited: $counting->contains(fn (Advisory $notice): bool => $notice->isExploited()),
            noticeIssueIds: $notices->mapWithKeys(fn (Advisory $notice): array => [(int) $notice->id => self::issueIdsOf($notice)])->all(),
            knownExploitedCves: $counting->flatMap(fn (Advisory $notice): array => KnownExploitation::cves($notice->known_exploited))->unique()->sort()->values()->all(),
        );
    }

    /**
     * The branches of the runtimes the vendor did not rule out (see Incident::scopeFor()) and has no confirmed fix for
     * yet, naturally sorted. A runtime whose branch is not known is listed by its version: no fix is matched to it.
     *
     * @param  Collection<int, Runtime>  $runtimes
     * @param  array<string, array{version: string, notice: Advisory}>  $patches
     * @return list<string>
     */
    public static function waitingBranches(Incident $incident, Collection $runtimes, array $patches): array
    {
        return $runtimes
            ->filter(fn (Runtime $runtime): bool => $incident->scopeFor($runtime) !== IncidentScope::NotAffected && ($runtime->branch === null || ! isset($patches[$runtime->branch])))
            ->map(fn (Runtime $runtime): string => $runtime->branch ?? (string) ($runtime->version ?? $runtime->raw_version))
            ->unique()
            ->sort(SORT_NATURAL)
            ->values()
            ->all();
    }

    /**
     * The CVE and GHSA ids of a pre-notice, by which another pre-notice joins its incident on its own.
     *
     * @return list<string>
     */
    private static function issueIdsOf(Advisory $notice): array
    {
        return collect($notice->aliases ?? [])
            ->filter(fn (mixed $alias): bool => is_string($alias) && preg_match('/^(CVE|GHSA)-/i', $alias) === 1)
            ->map(fn (string $alias): string => strtoupper($alias))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Whether an assessment made on `$snapshot` still holds for this exposure: it is still the same issue (every
     * pre-notice it showed is still part of the incident, and every other one shares a CVE or GHSA id with one it
     * showed, as a pre-notice joining on its own does; a merged-in issue or a pre-notice without a shared id ends it),
     * the severity is not higher,
     * the incident is not newly exploited, CISA KEV lists no CVE id of it the snapshot did not show, and no service and branch the vendor confirmed or did not rule out is new
     * to it, or moved from not affected or unknown to confirmed. A branch getting its fix, a service leaving and a
     * branch being ruled out ask nothing new of the team, so they keep it.
     *
     * @param  array<string, mixed>|null  $snapshot  See toArray().
     */
    public function isCoveredBy(?array $snapshot): bool
    {
        if (! is_array($snapshot) || ! isset($snapshot['notice_ids'], $snapshot['services'], $snapshot['severity'])) {
            return false;
        }

        $shownIds = array_map(intval(...), (array) $snapshot['notice_ids']);
        $shownIssueIds = array_map(strval(...), (array) ($snapshot['issue_ids'] ?? []));

        if (array_diff($shownIds, $this->noticeIds) !== []) {
            return false;
        }

        foreach ($this->noticeIssueIds as $noticeId => $issueIds) {
            if (! in_array($noticeId, $shownIds, true) && array_intersect($issueIds, $shownIssueIds) === []) {
                return false;
            }
        }

        if ($this->severity->rank() > (Severity::tryFrom((string) $snapshot['severity']) ?? Severity::Unknown)->rank()) {
            return false;
        }

        if ($this->isExploited && ($snapshot['exploited'] ?? false) !== true) {
            return false;
        }

        if (array_diff($this->knownExploitedCves, (array) ($snapshot['known_exploited'] ?? [])) !== []) {
            return false;
        }

        $seen = collect((array) $snapshot['services'])->groupBy(fn (array $service): string => "{$service['service_id']}|{$service['branch']}");

        return collect($this->services)
            ->reject(fn (array $service): bool => $service['scope'] === IncidentScope::NotAffected->value)
            ->every(function (array $service) use ($seen): bool {
                $scopes = $seen->get("{$service['service_id']}|{$service['branch']}", collect())->pluck('scope');

                return $service['scope'] === IncidentScope::Confirmed->value
                    ? $scopes->contains(IncidentScope::Confirmed->value)
                    : $scopes->contains(IncidentScope::Confirmed->value) || $scopes->contains(IncidentScope::Unknown->value);
            });
    }

    /**
     * The snapshot an assessment keeps.
     *
     * @return array{notice_ids: list<int>, issue_ids: list<string>, services: list<array{service_id: int, service: string, branch: string, scope: string, patch: string|null}>, severity: string, exploited: bool, known_exploited: list<string>}
     */
    public function toArray(): array
    {
        return [
            'notice_ids' => $this->noticeIds,
            'issue_ids' => collect($this->noticeIssueIds)->flatten()->unique()->sort()->values()->all(),
            'services' => $this->services,
            'severity' => $this->severity->value,
            'exploited' => $this->isExploited,
            'known_exploited' => $this->knownExploitedCves,
        ];
    }

    /**
     * The version of the exposure the admin's form posts back: any change to it, a fix included, means the admin did
     * not see what is there now.
     */
    public function hash(): string
    {
        return hash('sha256', (string) json_encode($this->toArray()));
    }
}
