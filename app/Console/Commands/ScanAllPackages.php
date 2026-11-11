<?php

namespace App\Console\Commands;

use App\Actions\EvaluateWorkflows;
use App\Actions\ScanPackages;
use App\Actions\SendFindingAlerts;
use App\Actions\SyncComponents;
use App\Models\Deployment;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('psm:scan-packages')]
#[Description('Match the packages of every current deployment against Packagist, RubySec and OSV and alert the owning teams about new advisories')]
class ScanAllPackages extends Command
{
    /**
     * Handover 5.2: every 6 hours, so an advisory published in Packagist, RubySec or OSV reaches the team within 6
     * hours. Lockfiles not read yet are read first. Deployments whose packages are all gone are scanned too, so their findings get resolved.
     * GitHub Actions workflows are evaluated afterwards, with the references the scan resolved again.
     */
    public function handle(SyncComponents $syncComponents, ScanPackages $scanPackages, EvaluateWorkflows $evaluateWorkflows, SendFindingAlerts $sendFindingAlerts): int
    {
        $deployments = Deployment::where('is_current', true)
            ->where(fn ($query) => $query->whereHas('lockfiles')->orWhereHas('components')->orWhereHas('openPackageFindings'))
            ->orderBy('id')
            ->get();

        foreach ($deployments as $deployment) {
            $syncComponents->handle($deployment);
        }

        $failed = false;

        try {
            $scanPackages->handle($deployments->modelKeys());
            $this->components->info("{$deployments->count()} deployments scanned.");
        } catch (Throwable $exception) {
            $failed = true;
            report($exception);
            $this->components->error($exception->getMessage());
        }

        $evaluateWorkflows->handleAll();

        $this->components->info($sendFindingAlerts->handle().' finding cards queued.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
