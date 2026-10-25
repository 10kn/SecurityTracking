<?php

namespace App\Jobs;

use App\Actions\SyncServiceLockfiles;
use App\Models\Service;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Reads a service's lockfiles from its repository outside the daily schedule: when an admin asks for it, or right
 * after the repository, branch or directory of the service changed.
 */
class SyncServiceLockfilesJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public Service $service) {}

    public function uniqueId(): string
    {
        return (string) $this->service->id;
    }

    public function handle(SyncServiceLockfiles $syncServiceLockfiles): void
    {
        $syncServiceLockfiles->handle($this->service);
    }
}
