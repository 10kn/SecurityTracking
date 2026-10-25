<?php

namespace App\Console\Commands;

use App\Actions\EvaluateRuntimes;
use App\Actions\SendFindingAlerts;
use App\Actions\SyncTechnologyCatalog;
use App\Enums\Technology;
use App\Models\Runtime;
use App\Models\RuntimeBranch;
use App\Models\RuntimeRelease;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('psm:sync-runtimes {--technology=* : Technologies to sync, e.g. "php"; all when omitted}')]
#[Description('Refresh the version catalogs from endoflife.date and the official release feeds, then re-evaluate runtimes')]
class SyncRuntimeCatalogs extends Command
{
    /**
     * A failing source does not stop the others. Runtimes are re-evaluated either way, so a catalog that stays broken
     * past its threshold turns its runtimes unknown. Alerts are sent once at the end, so each issue gets one card per team.
     */
    public function handle(SyncTechnologyCatalog $syncTechnologyCatalog, EvaluateRuntimes $evaluateRuntimes, SendFindingAlerts $sendFindingAlerts): int
    {
        $technologies = array_filter(
            Technology::cases(),
            fn (Technology $technology): bool => $this->option('technology') === [] || in_array($technology->value, $this->option('technology'), true),
        );

        $failures = 0;

        foreach ($technologies as $technology) {
            try {
                $syncTechnologyCatalog->handle($technology);
                $this->components->info("{$technology->getLabel()}: ".RuntimeBranch::where('technology', $technology)->count().' branches, '.RuntimeRelease::where('technology', $technology)->count().' releases');
            } catch (Throwable $exception) {
                $failures++;
                report($exception);
                $this->components->error("{$technology->getLabel()}: {$exception->getMessage()}");
            }

            $evaluateRuntimes->handle(Runtime::where('technology', $technology)->get());
        }

        $sendFindingAlerts->handle();

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
