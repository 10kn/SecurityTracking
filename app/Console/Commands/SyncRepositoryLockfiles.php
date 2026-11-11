<?php

namespace App\Console\Commands;

use App\Actions\SyncServiceLockfiles;
use App\Models\Service;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Throwable;

#[Signature('psm:sync-lockfiles')]
#[Description('Read the lockfiles of every service that syncs them from its GitHub repository')]
class SyncRepositoryLockfiles extends Command
{
    /**
     * Handover D12: once a day. Services whose lockfiles changed are evaluated again by the queued listener, which
     * also sends the alerts of the findings that opened.
     */
    public function handle(SyncServiceLockfiles $syncServiceLockfiles): int
    {
        $services = Service::whereNotNull('repository')->with('project')->orderBy('id')->get();
        $failed = false;

        foreach ($services as $service) {
            try {
                $syncServiceLockfiles->handle($service);
            } catch (LockTimeoutException) {
                $this->components->warn("{$service->project->name} / {$service->name}: another sync of this service is still running.");
            } catch (Throwable $exception) {
                $failed = true;
                report($exception);
                $this->components->error("{$service->project->name} / {$service->name}: {$exception->getMessage()}");
            }
        }

        $syncServiceLockfiles->forgetUnusedSource();

        $this->components->info("{$services->count()} services synced.");

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
