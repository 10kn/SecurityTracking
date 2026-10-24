<?php

namespace App\Jobs;

use App\Actions\EvaluateRuntimes;
use App\Actions\SendFindingAlerts;
use App\Actions\SyncTechnologyCatalog;
use App\Models\Technology;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Syncs one technology's catalog outside the hourly schedule, e.g. right after an admin adds the technology, then
 * re-evaluates the runtimes that use it and sends the alerts of the findings that opened.
 */
class SyncTechnologyCatalogJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public Technology $technology) {}

    public function uniqueId(): string
    {
        return (string) $this->technology->id;
    }

    public function handle(SyncTechnologyCatalog $syncTechnologyCatalog, EvaluateRuntimes $evaluateRuntimes, SendFindingAlerts $sendFindingAlerts): void
    {
        try {
            $syncTechnologyCatalog->handle($this->technology);
        } finally {
            $evaluateRuntimes->handle($this->technology->runtimes()->with('technology')->get());
            $sendFindingAlerts->handle();
        }
    }
}
