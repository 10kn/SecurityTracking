<?php

namespace App\Actions;

use App\Enums\RuntimeStatus;
use App\Models\Deployment;
use App\Models\Finding;
use App\Models\Runtime;
use App\Services\RuntimeEvaluator;
use App\Services\RuntimeFindings;

/**
 * Evaluates runtimes against the version catalog, stores the result and syncs the runtime findings of the deployments
 * involved. Running it again gives the same result.
 *
 * Alerts are not sent here: callers run SendFindingAlerts once they finish, so findings about the same issue that
 * open in one run end up in one Google Chat card.
 */
class EvaluateRuntimes
{
    public function __construct(private SyncFindings $syncFindings, private RuntimeFindings $runtimeFindings) {}

    /**
     * @param  iterable<Runtime>  $runtimes
     */
    public function handle(iterable $runtimes): void
    {
        $evaluator = new RuntimeEvaluator;
        $deploymentIds = [];

        foreach ($runtimes as $runtime) {
            $evaluation = $evaluator->evaluate($runtime->technology, $runtime->raw_version);

            $runtime->update([
                'version' => $evaluation->version,
                'branch' => $evaluation->branch,
                'status' => $evaluation->status,
                'status_reason' => $evaluation->reason,
                'status_context' => $evaluation->context,
                'evaluated_at' => now(),
            ]);

            $deploymentIds[$runtime->deployment_id] = true;
        }

        $this->syncRuntimeFindings(array_keys($deploymentIds));
    }

    /**
     * Syncs every runtime of the deployments, so the findings of a runtime that was removed get resolved too. A runtime
     * that evaluates unknown keeps its open findings: missing data is never treated as fixed.
     *
     * @param  list<int>  $deploymentIds
     */
    public function syncRuntimeFindings(array $deploymentIds): void
    {
        $this->runtimeFindings->forgetPreNotices();

        Deployment::with(['service.project', 'runtimes'])
            ->whereKey($deploymentIds)
            ->each(function (Deployment $deployment): void {
                $unknownRuntimeIds = $deployment->runtimes->where('status', RuntimeStatus::Unknown)->modelKeys();

                $this->syncFindings->handle(
                    $deployment,
                    RuntimeFindings::RULES,
                    $deployment->runtimes->map(fn (Runtime $runtime) => $this->runtimeFindings->for($runtime))->filter()->values()->all(),
                    fn (Finding $finding): bool => in_array($finding->runtime_id, $unknownRuntimeIds, true),
                );
            });
    }
}
