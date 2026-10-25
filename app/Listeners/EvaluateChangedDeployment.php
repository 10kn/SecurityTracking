<?php

namespace App\Listeners;

use App\Actions\EvaluateRuntimes;
use App\Actions\ScanPackages;
use App\Actions\SendFindingAlerts;
use App\Actions\SyncComponents;
use App\Events\DeploymentInventoryChanged;
use Illuminate\Contracts\Queue\ShouldQueue;
use Throwable;

/**
 * Re-evaluates a deployment right after an admin changes its versions or lockfiles (handover 5.2: "within seconds"):
 * runtimes against the catalog, then the lockfiles' packages against OSV. Then sends the alerts of the findings that
 * opened. When OSV fails the runtime alerts still go out; the next psm:scan-packages tries the packages again.
 */
class EvaluateChangedDeployment implements ShouldQueue
{
    public function __construct(
        private EvaluateRuntimes $evaluateRuntimes,
        private SyncComponents $syncComponents,
        private ScanPackages $scanPackages,
        private SendFindingAlerts $sendFindingAlerts,
    ) {}

    public function handle(DeploymentInventoryChanged $event): void
    {
        $deployment = $event->deployment;

        $this->evaluateRuntimes->handle($deployment->runtimes()->get());
        $this->evaluateRuntimes->syncRuntimeFindings([$deployment->id]);

        $this->syncComponents->handle($deployment);

        try {
            $this->scanPackages->handle([$deployment->id]);
        } catch (Throwable $exception) {
            report($exception);
        }

        $this->sendFindingAlerts->handle();
    }
}
