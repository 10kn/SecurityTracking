<?php

namespace App\Console\Commands;

use App\Actions\EvaluateWorkflows;
use App\Actions\SendFindingAlerts;
use App\Actions\SyncGitHubRunnerImages as SyncImages;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('psm:sync-runner-images')]
#[Description('Read when GitHub removes its hosted runner images and re-evaluate the workflows of every service')]
class SyncGitHubRunnerImages extends Command
{
    /**
     * Workflows are evaluated even when endoflife.date cannot be read, so a removal day that came is still noticed with
     * the dates read last.
     */
    public function handle(SyncImages $syncImages, EvaluateWorkflows $evaluateWorkflows, SendFindingAlerts $sendFindingAlerts): int
    {
        $failed = false;

        try {
            $syncImages->handle();
        } catch (Throwable $exception) {
            $failed = true;
            report($exception);
            $this->components->error($exception->getMessage());
        }

        $evaluateWorkflows->handleAll();
        $sendFindingAlerts->handle();

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
