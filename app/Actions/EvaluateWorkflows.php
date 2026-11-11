<?php

namespace App\Actions;

use App\Enums\Ecosystem;
use App\Enums\FindingRule;
use App\Enums\FindingState;
use App\Enums\Technology;
use App\Models\Deployment;
use App\Models\Finding;
use App\Models\GitHubRunnerImage;
use App\Models\RuntimeBranch;
use App\Services\WorkflowFindings;
use Closure;

/**
 * Syncs the GitHub Actions lifecycle findings of deployments (see WorkflowFindings): after their workflows change,
 * after the action references were resolved again (psm:scan-packages), and after the runner images were synced. While
 * the runner image catalog or the Node.js catalog is stale, the open findings it decides stay open: unknown is never
 * ok.
 *
 * Alerts are not sent here: callers run SendFindingAlerts once they finish.
 */
class EvaluateWorkflows
{
    public function __construct(private SyncFindings $syncFindings, private WorkflowFindings $workflowFindings) {}

    /**
     * @param  list<int>  $deploymentIds
     */
    public function handle(array $deploymentIds): void
    {
        if ($deploymentIds === []) {
            return;
        }

        $images = GitHubRunnerImage::all()->keyBy('name');
        $nodeBranches = RuntimeBranch::where('technology', Technology::Nodejs)->get()->keyBy('branch');
        $keepOpen = $this->keepOpen();

        Deployment::with([
            'service.project',
            'lockfiles' => fn ($query) => $query->where('ecosystem', Ecosystem::GitHubActions),
            'components' => fn ($query) => $query->where('ecosystem', Ecosystem::GitHubActions),
        ])
            ->whereKey($deploymentIds)
            ->get()
            ->each(fn (Deployment $deployment) => $this->syncFindings->handle(
                $deployment,
                WorkflowFindings::RULES,
                $this->workflowFindings->for($deployment, $images, $nodeBranches),
                $keepOpen,
            ));
    }

    /**
     * Every current deployment with workflows, and every one with such findings still open, so they get resolved.
     */
    public function handleAll(): void
    {
        $this->handle(Deployment::where('is_current', true)
            ->where(fn ($query) => $query
                ->whereHas('lockfiles', fn ($lockfiles) => $lockfiles->where('ecosystem', Ecosystem::GitHubActions))
                ->orWhereHas('findings', fn ($findings) => $findings->where('state', FindingState::Open)->whereIn('rule', WorkflowFindings::RULES)))
            ->orderBy('id')
            ->pluck('id')
            ->all());
    }

    /**
     * @return (Closure(Finding): bool)|null
     */
    private function keepOpen(): ?Closure
    {
        $staleRules = array_values(array_filter([
            SyncGitHubRunnerImages::isCurrent() ? null : FindingRule::RunnerImageEol,
            Technology::Nodejs->catalogHeartbeat()?->isStale() === false ? null : FindingRule::ActionRuntimeEol,
        ]));

        return $staleRules === [] ? null : fn (Finding $finding): bool => in_array($finding->rule, $staleRules, true);
    }
}
