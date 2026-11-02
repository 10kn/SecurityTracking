<?php

namespace App\Actions;

use App\Enums\FindingEventType;
use App\Enums\FindingRule;
use App\Enums\Severity;
use App\Enums\Technology;
use App\Models\Finding;
use App\Models\IncidentTeamPatch;
use App\Services\AlertPolicy;
use App\Support\Version;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

/**
 * A "Đã có bản vá" card stands for the open "thiếu bản vá" findings of the branch, in the projects whose services it
 * lists, whose upgrade target is the patched version or an earlier one: they take the incident's severity when it is
 * higher and count as sent, so they send no card of their own. A finding asking for a later version is left alone, since the vendor has not said that
 * release is the fix. The card and the findings it stands for share one deadline: that of the incident's severity
 * from now, the date CISA KEV asks a CVE of the incident fixed by, or a finding's own, whichever is earliest, so a
 * deadline is never pushed back. RuntimeFindings gives findings
 * opened later the same severity (see IncidentTeamPatch::covering()).
 */
class CoverPatchedFindings
{
    public function __construct(private AlertPolicy $policy) {}

    /**
     * The findings a card about `$version` of the branch stands for, locked until the transaction ends: those of the
     * projects whose services it lists on the branch, never those of a paused project.
     *
     * @param  list<int>  $projectIds
     * @return Collection<int, Finding>
     */
    public function findingsFor(int $teamId, array $projectIds, Technology $technology, string $branch, string $version): Collection
    {
        return Finding::open()
            ->where('team_id', $teamId)
            ->whereHas('deployment.service', fn ($query) => $query->whereIn('project_id', $projectIds))
            ->where('rule', FindingRule::RuntimeOutdatedSecurity)
            ->whereHas('runtime', fn ($query) => $query->where('technology', $technology)->where('branch', $branch))
            ->lockForUpdate()
            ->get()
            ->filter(fn (Finding $finding): bool => is_string($finding->context['latest'] ?? null) && ! Version::isNewer($finding->context['latest'], $version))
            ->values();
    }

    /**
     * The deadline the card shows: that of `$severity` from now, the CISA KEV due date of the incident, or the earliest
     * of the findings it stands for.
     *
     * @param  Collection<int, Finding>  $findings
     */
    public function deadlineFor(Severity $severity, Collection $findings, ?CarbonInterface $knownExploitedDueDate = null): ?CarbonInterface
    {
        return collect([$this->policy->deadlineFor($severity, now()), $knownExploitedDueDate, ...$findings->pluck('sla_due_at')])->filter()->min();
    }

    /**
     * @param  Collection<int, Finding>  $findings  The findings the card stands for (see findingsFor()).
     */
    public function handle(IncidentTeamPatch $patch, Collection $findings, ?CarbonInterface $knownExploitedDueDate = null): void
    {
        $findings->each(fn (Finding $finding) => $this->cover($finding, $patch, $knownExploitedDueDate));
    }

    private function cover(Finding $finding, IncidentTeamPatch $patch, ?CarbonInterface $knownExploitedDueDate): void
    {
        $previous = $finding->severity;
        $isRaised = $patch->severity->rank() > $previous->rank();
        $severity = $isRaised ? $patch->severity : $finding->severity;

        $finding->update([
            'severity' => $severity,
            'context' => [...($finding->context ?? []), 'covered_by_patch_id' => $patch->id],
            'notified_severity' => $severity,
            'notified_at' => now(),
            'sla_due_at' => $this->deadlineFor($patch->severity, new Collection([$finding]), $knownExploitedDueDate),
            ...($isRaised ? ['acknowledged_at' => null, 'acknowledged_by' => null] : []),
        ]);

        if ($isRaised) {
            $finding->events()->create(['type' => FindingEventType::Escalated, 'data' => ['from' => $previous->value, 'to' => $severity->value, 'incident_patch_id' => $patch->id]]);
        }
    }
}
