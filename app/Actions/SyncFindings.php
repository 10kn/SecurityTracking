<?php

namespace App\Actions;

use App\Enums\FindingEventType;
use App\Enums\FindingRule;
use App\Enums\FindingState;
use App\Models\Deployment;
use App\Models\Finding;
use App\Services\FindingData;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Makes a deployment's findings match what an evaluation found (handover 5.4). Running it again with the same input
 * changes nothing.
 *
 * A new fingerprint opens a finding, a resolved one that matches again is reopened, and an open finding that no longer
 * matches is resolved. A rule change (outdated_security → eol) has a new fingerprint, so it closes the old finding and
 * opens a new one. Every change is recorded in finding_events.
 */
class SyncFindings
{
    /**
     * @param  list<FindingRule>  $rules  The rules the evaluation covers. Open findings of other rules are left alone.
     * @param  list<FindingData>  $findings
     * @param  (Closure(Finding): bool)|null  $keepOpen  Open findings the evaluation could not judge, e.g. the runtime
     *                                                   evaluated unknown, stay open rather than counting as fixed.
     */
    public function handle(Deployment $deployment, array $rules, array $findings, ?Closure $keepOpen = null): void
    {
        $teamId = $deployment->service->project->team_id;

        DB::transaction(function () use ($deployment, $rules, $findings, $keepOpen, $teamId): void {
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
                $this->store($deployment, $teamId, $data, $existing->get($fingerprint));
            }
        });
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
            ]);

            $finding->events()->create(['type' => FindingEventType::Opened, 'data' => ['severity' => $data->severity->value]]);

            return;
        }

        if ($finding->state === FindingState::Resolved) {
            $finding->update([
                ...$attributes,
                'state' => FindingState::Open,
                'resolved_at' => null,
                'notified_severity' => null,
                'notified_at' => null,
            ]);

            $finding->events()->create(['type' => FindingEventType::Opened, 'data' => ['severity' => $data->severity->value, 'reopened' => true]]);

            return;
        }

        $previousSeverity = $finding->severity;

        $finding->update($attributes);

        if ($data->severity->rank() > $previousSeverity->rank()) {
            $finding->events()->create(['type' => FindingEventType::Escalated, 'data' => ['from' => $previousSeverity->value, 'to' => $data->severity->value]]);
        }
    }
}
