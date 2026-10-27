<?php

namespace App\Jobs;

use App\Actions\SyncServiceLockfiles;
use App\Models\Service;
use DateTimeInterface;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Reads a service's lockfiles from its repository outside the daily schedule: when an admin asks for it, or right
 * after the repository, branch, directory or exclusions of the service changed.
 *
 * A job waiting in the queue takes no copies, but one dispatched while another runs is queued: settings saved during a
 * sync are read by the next one (the running sync keeps nothing of what it read, see SyncServiceLockfiles).
 */
class SyncServiceLockfilesJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    /**
     * A job does not wait for another sync of the service inside the worker: it comes back later, for longer than a
     * sync may hold the service. An error fails it at once.
     */
    public int $maxExceptions = 1;

    public function __construct(public Service $service) {}

    public function uniqueId(): string
    {
        return (string) $this->service->id;
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(20);
    }

    public function handle(SyncServiceLockfiles $syncServiceLockfiles): void
    {
        try {
            $syncServiceLockfiles->handle($this->service, lockWaitSeconds: 0);
        } catch (LockTimeoutException) {
            $this->release(30);
        }
    }
}
