<?php

namespace App\Actions;

use App\Enums\FindingEventType;
use App\Enums\FindingRule;
use App\Enums\FindingState;
use App\Enums\Technology;
use App\Models\Deployment;
use App\Models\Finding;
use App\Models\IncidentTeamPatch;
use App\Models\Runtime;
use App\Services\AlertPolicy;
use App\Services\FindingData;
use App\Services\Kev\KnownExploitation;
use App\Support\IncidentLock;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Makes a deployment's findings match what an evaluation found (handover 5.4). Running it again with the same input
 * changes nothing.
 *
 * A new fingerprint opens a finding, a resolved one that matches again is reopened, and an open finding that no longer
 * matches is resolved. A rule change (outdated_security → eol) has a new fingerprint, so it closes the old finding and
 * opens a new one. Every change is recorded in finding_events.
 *
 * Each occurrence gets the handling deadline of its severity, or its own (see FindingData). A rise in severity can
 * only bring the deadline forward, and it clears "Đã đọc", so the team is reminded again; a reopened finding starts
 * over. CISA KEV listing a CVE of the finding it did not list before (see KnownExploitation) does the same and counts
 * as not sent, so the team is told even when the finding was already at the KEV severity; the CISA due date can only
 * bring the deadline forward too, on every sync, which clears "Đã đọc" so the new deadline is reminded. A "thiếu bản vá" finding its "Đã có bản vá" card no longer stands for (it now asks for a later release than
 * the card's) counts as not sent again, so it gets a card of its own at its severity; its deadline stays.
 */
class SyncFindings
{
    public function __construct(private AlertPolicy $policy) {}

    /**
     * @param  list<FindingRule>  $rules  The rules the evaluation covers. Open findings of other rules are left alone.
     * @param  list<FindingData>  $findings
     * @param  (Closure(Finding): bool)|null  $keepOpen  Open findings the evaluation could not judge, e.g. the runtime
     *                                                   evaluated unknown, stay open rather than counting as fixed.
     */
    public function handle(Deployment $deployment, array $rules, array $findings, ?Closure $keepOpen = null): void
    {
        $teamId = $deployment->service->project->team_id;

        $patchable = $this->patchableTechnologies($deployment, $rules, $findings);

        DB::transaction(function () use ($deployment, $rules, $findings, $keepOpen, $teamId, $patchable): void {
            IncidentLock::take($patchable);

            $wanted = collect($findings)->keyBy('fingerprint');

            $deployment->findings()
                ->open()
                ->whereIn('rule', $rules)
                ->whereNotIn('fingerprint', $wanted->keys())
                ->lockForUpdate()
                ->get()
                ->reject(fn (Finding $finding): bool => $keepOpen !== null && $keepOpen($finding))
                ->each(function (Finding $finding): void {
                    $finding->update(['state' => FindingState::Resolved, 'resolved_at' => now()]);
                    $finding->events()->create(['type' => FindingEventType::Resolved]);
                });

            $existing = Finding::whereIn('fingerprint', $wanted->keys())->lockForUpdate()->get()->keyBy('fingerprint');

            foreach ($wanted as $fingerprint => $data) {
                $this->store($deployment, $teamId, $this->withCoveringPatch($teamId, $deployment->service->project_id, $data), $existing->get($fingerprint));
            }
        });
    }

    /**
     * Applies what an evaluation found to one open finding only, e.g. new CISA KEV evidence read from the database (see
     * RefreshKnownExploitedFindings), with the same transitions as handle(). Nothing happens when the finding closed or
     * is another one meanwhile.
     */
    public function refresh(Finding $finding, FindingData $data): void
    {
        DB::transaction(function () use ($finding, $data): void {
            $locked = Finding::open()->with('deployment')->whereKey($finding->id)->lockForUpdate()->first();

            if ($locked === null || $locked->fingerprint !== $data->fingerprint) {
                return;
            }

            $this->store($locked->deployment, $locked->team_id, $data, $locked);
        });
    }

    /**
     * The technologies of the "thiếu bản vá" findings the evaluation stores or may resolve: their IncidentLock is taken
     * before any row, so a "Đã có bản vá" card being queued and this evaluation each see what the other wrote.
     *
     * @param  list<FindingRule>  $rules
     * @param  list<FindingData>  $findings
     * @return Collection<int, Technology>
     */
    private function patchableTechnologies(Deployment $deployment, array $rules, array $findings): Collection
    {
        $runtimeIds = collect($findings)
            ->filter(fn (FindingData $data): bool => $data->rule === FindingRule::RuntimeOutdatedSecurity && $data->runtimeId !== null)
            ->pluck('runtimeId');

        if (in_array(FindingRule::RuntimeOutdatedSecurity, $rules, true)) {
            $runtimeIds = $runtimeIds->merge($deployment->findings()->open()->where('rule', FindingRule::RuntimeOutdatedSecurity)->whereNotNull('runtime_id')->pluck('runtime_id'));
        }

        return $runtimeIds->isEmpty() ? collect() : Runtime::whereKey($runtimeIds->unique()->all())->get()->pluck('technology');
    }

    /**
     * A "thiếu bản vá" finding takes the "Đã có bản vá" card covering it as of now, not as of when the evaluation read
     * it (see IncidentTeamPatch::covering()): a card queued meanwhile already raised the finding and counted it as sent
     * (see CoverPatchedFindings), and the older result must not undo that. Cards are never withdrawn, so only a cover
     * the data lacks is added.
     */
    private function withCoveringPatch(int $teamId, int $projectId, FindingData $data): FindingData
    {
        $latest = $data->context['latest'] ?? null;
        $branch = $data->context['branch'] ?? null;
        $runtime = $data->rule === FindingRule::RuntimeOutdatedSecurity && is_string($latest) && is_string($branch) && $data->runtimeId !== null ? Runtime::find($data->runtimeId) : null;
        $patch = $runtime === null ? null : IncidentTeamPatch::covering($teamId, $projectId, $runtime->technology, $branch, $latest);

        if ($patch === null) {
            return $data;
        }

        return new FindingData(
            fingerprint: $data->fingerprint,
            rule: $data->rule,
            severity: $patch->severity->rank() > $data->severity->rank() ? $patch->severity : $data->severity,
            title: $data->title,
            groupKey: $data->groupKey,
            context: [...$data->context, 'covered_by_patch_id' => $patch->id],
            runtimeId: $data->runtimeId,
            componentId: $data->componentId,
            advisoryId: $data->advisoryId,
            deadline: $data->deadline,
        );
    }

    /**
     * In the application timezone: a time is stored as written, without its offset.
     */
    private function deadline(FindingData $data): ?CarbonInterface
    {
        return $data->deadline?->copy()->setTimezone(config('app.timezone')) ?? $this->policy->deadlineFor($data->severity, now());
    }

    private function store(Deployment $deployment, int $teamId, FindingData $data, ?Finding $finding): void
    {
        $attributes = [
            'team_id' => $teamId,
            'runtime_id' => $data->runtimeId,
            'component_id' => $data->componentId,
            'advisory_id' => $data->advisoryId,
            'severity' => $data->severity,
            'title' => $data->title,
            'group_key' => $data->groupKey,
            'context' => $data->context,
            'last_seen_at' => now(),
        ];

        if ($finding === null) {
            $finding = $deployment->findings()->create([
                ...$attributes,
                'fingerprint' => $data->fingerprint,
                'rule' => $data->rule,
                'state' => FindingState::Open,
                'first_seen_at' => now(),
                'sla_due_at' => $this->deadline($data),
            ]);

            $finding->events()->create(['type' => FindingEventType::Opened, 'data' => ['severity' => $data->severity->value]]);
            $this->recordKnownExploited($finding, $data, KnownExploitation::cves($data->context['known_exploited'] ?? null));

            return;
        }

        if ($finding->state === FindingState::Resolved) {
            $finding->update([
                ...$attributes,
                'state' => FindingState::Open,
                'resolved_at' => null,
                'sla_due_at' => $this->deadline($data),
                'notified_severity' => null,
                'notified_at' => null,
                'acknowledged_at' => null,
                'acknowledged_by' => null,
            ]);

            $finding->events()->create(['type' => FindingEventType::Opened, 'data' => ['severity' => $data->severity->value, 'reopened' => true]]);
            $this->recordKnownExploited($finding, $data, KnownExploitation::cves($data->context['known_exploited'] ?? null));

            return;
        }

        $previousSeverity = $finding->severity;
        $isRaised = $data->severity->rank() > $previousSeverity->rank();
        $newlyKnownExploited = array_values(array_diff(KnownExploitation::cves($data->context['known_exploited'] ?? null), KnownExploitation::cves($finding->context['known_exploited'] ?? null)));
        $coveredBy = $finding->context['covered_by_patch_id'] ?? null;

        if (($coveredBy !== null && ($data->context['covered_by_patch_id'] ?? null) !== $coveredBy) || $newlyKnownExploited !== []) {
            $attributes = [...$attributes, 'notified_severity' => null, 'notified_at' => null];
        }

        $deadline = $finding->sla_due_at;

        if ($isRaised || $newlyKnownExploited !== []) {
            $deadline = self::earliest($deadline, $this->deadline($data));

            $attributes = [...$attributes, 'acknowledged_at' => null, 'acknowledged_by' => null];
        }

        $deadline = self::earliest($deadline, KnownExploitation::dueDate($data->context['known_exploited'] ?? null));

        if ($deadline?->getTimestamp() !== $finding->sla_due_at?->getTimestamp()) {
            $attributes = [...$attributes, 'sla_due_at' => $deadline, 'acknowledged_at' => null, 'acknowledged_by' => null];
        }

        $finding->update($attributes);

        if ($isRaised) {
            $finding->events()->create(['type' => FindingEventType::Escalated, 'data' => ['from' => $previousSeverity->value, 'to' => $data->severity->value]]);
        }

        $this->recordKnownExploited($finding, $data, $newlyKnownExploited);
    }

    /**
     * Records that CISA KEV lists CVE ids of the finding it did not list before.
     *
     * @param  list<string>  $cves
     */
    private function recordKnownExploited(Finding $finding, FindingData $data, array $cves): void
    {
        if ($cves === []) {
            return;
        }

        $finding->events()->create(['type' => FindingEventType::KnownExploited, 'data' => [
            'cves' => $cves,
            'due_date' => KnownExploitation::dueDate($data->context['known_exploited'] ?? null)?->toDateString(),
        ]]);
    }

    /**
     * The earlier of two deadlines; a missing one does not count.
     */
    private static function earliest(?CarbonInterface $deadline, ?CarbonInterface $other): ?CarbonInterface
    {
        return $deadline === null || ($other !== null && $other->lt($deadline)) ? ($other ?? $deadline) : $deadline;
    }
}
