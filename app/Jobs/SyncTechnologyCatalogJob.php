<?php

namespace App\Jobs;

use App\Actions\EvaluateRuntimes;
use App\Actions\SendFindingAlerts;
use App\Actions\SyncTechnologyCatalog;
use App\Enums\Technology;
use App\Models\Runtime;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Syncs one technology's catalog outside the hourly schedule, when an admin asks for it, then re-evaluates the
 * runtimes that use it and sends the alerts of the findings that opened.
 */
class SyncTechnologyCatalogJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public Technology $technology) {}

    public function uniqueId(): string
    {
        return $this->technology->value;
    }

    public function handle(SyncTechnologyCatalog $syncTechnologyCatalog, EvaluateRuntimes $evaluateRuntimes, SendFindingAlerts $sendFindingAlerts): void
    {
        try {
            $syncTechnologyCatalog->handle($this->technology);
        } finally {
            $evaluateRuntimes->handle(Runtime::where('technology', $this->technology)->get());
            $sendFindingAlerts->handle();
        }
    }
}
