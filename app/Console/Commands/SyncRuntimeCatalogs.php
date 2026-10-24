<?php

namespace App\Console\Commands;

use App\Actions\EvaluateRuntimes;
use App\Actions\SendFindingAlerts;
use App\Actions\SyncTechnologyCatalog;
use App\Models\Technology;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('psm:sync-runtimes {--technology=* : Slugs of the technologies to sync; all when omitted}')]
#[Description('Refresh the version catalogs from endoflife.date and the official release feeds, then re-evaluate runtimes')]
class SyncRuntimeCatalogs extends Command
{
    /**
     * A failing source does not stop the others. Runtimes are re-evaluated either way, so a catalog that stays broken
     * past its threshold turns its runtimes unknown. Alerts are sent once at the end, so each issue gets one card per team.
     */
    public function handle(SyncTechnologyCatalog $syncTechnologyCatalog, EvaluateRuntimes $evaluateRuntimes, SendFindingAlerts $sendFindingAlerts): int
    {
        $technologies = Technology::query()
            ->when($this->option('technology'), fn ($query, array $slugs) => $query->whereIn('slug', $slugs))
            ->orderBy('name')
            ->get();

        $failures = 0;

        foreach ($technologies as $technology) {
            try {
                $syncTechnologyCatalog->handle($technology);
                $this->components->info("{$technology->name}: ".$technology->branches()->count().' branches, '.$technology->releases()->count().' releases');
            } catch (Throwable $exception) {
                $failures++;
                report($exception);
                $this->components->error("{$technology->name}: {$exception->getMessage()}");
            }

            $evaluateRuntimes->handle($technology->runtimes()->with('technology')->get());
        }

        $sendFindingAlerts->handle();

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
