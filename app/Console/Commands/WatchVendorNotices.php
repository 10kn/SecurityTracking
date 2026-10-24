<?php

namespace App\Console\Commands;

use App\Actions\EvaluateRuntimes;
use App\Actions\SendFindingAlerts;
use App\Actions\SendPreNoticeAlerts;
use App\Actions\SyncNoticeFeed;
use App\Models\Advisory;
use App\Models\Runtime;
use App\Models\Technology;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('psm:watch-notices')]
#[Description('Poll the vendor security feeds, store their pre-notices and warn every team that runs the technology')]
class WatchVendorNotices extends Command
{
    /**
     * Handover 5.2: polled every 15 minutes. Each feed is polled once, for the first technology that uses its release
     * source. A failing feed does not stop the others. A new or edited pre-notice re-syncs the runtime findings of its
     * technology, so a critical one escalates them right away instead of at the next catalog sync.
     */
    public function handle(
        SyncNoticeFeed $syncNoticeFeed,
        SendPreNoticeAlerts $sendPreNoticeAlerts,
        EvaluateRuntimes $evaluateRuntimes,
        SendFindingAlerts $sendFindingAlerts,
    ): int {
        $technologies = Technology::orderBy('id')
            ->get()
            ->filter(fn (Technology $technology): bool => $technology->release_source->noticeFeed() !== null)
            ->unique(fn (Technology $technology): string => $technology->release_source->value);

        $failures = 0;
        $changedTechnologyIds = [];

        foreach ($technologies as $technology) {
            $feed = $technology->release_source->getLabel();

            try {
                $advisories = collect($syncNoticeFeed->handle($technology));
                $this->components->info("{$feed}: {$advisories->count()} pre-notices");
            } catch (Throwable $exception) {
                $failures++;
                report($exception);
                $this->components->error("{$feed}: {$exception->getMessage()}");

                continue;
            }

            if ($advisories->contains(fn (Advisory $advisory): bool => $advisory->wasRecentlyCreated || $advisory->wasChanged())) {
                $changedTechnologyIds[] = $technology->id;
            }
        }

        if ($changedTechnologyIds !== []) {
            $evaluateRuntimes->syncRuntimeFindings(
                Runtime::whereIn('technology_id', $changedTechnologyIds)->distinct()->pluck('deployment_id')->map(fn (mixed $id): int => (int) $id)->all(),
            );
        }

        $this->components->info($sendPreNoticeAlerts->handle().' pre-notice cards queued.');

        $sendFindingAlerts->handle();

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
