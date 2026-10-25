<?php

namespace App\Console\Commands;

use App\Actions\EvaluateRuntimes;
use App\Actions\SendFindingAlerts;
use App\Actions\SendPreNoticeAlerts;
use App\Actions\SyncNoticeFeed;
use App\Enums\Technology;
use App\Models\Advisory;
use App\Models\Runtime;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('psm:watch-notices')]
#[Description('Poll the vendor security feeds, store their pre-notices and warn every team that runs the technology')]
class WatchVendorNotices extends Command
{
    /**
     * Handover 5.2: the feed of every technology is polled every 15 minutes. A failing feed does not stop the others.
     * A new or edited pre-notice re-syncs the runtime findings of its technology, so a critical one escalates them
     * right away instead of at the next catalog sync.
     */
    public function handle(
        SyncNoticeFeed $syncNoticeFeed,
        SendPreNoticeAlerts $sendPreNoticeAlerts,
        EvaluateRuntimes $evaluateRuntimes,
        SendFindingAlerts $sendFindingAlerts,
    ): int {
        $failures = 0;
        $changedTechnologies = [];

        foreach (Technology::cases() as $technology) {
            $feed = $technology->releaseSourceLabel();

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
                $changedTechnologies[] = $technology->value;
            }
        }

        if ($changedTechnologies !== []) {
            $evaluateRuntimes->syncRuntimeFindings(
                Runtime::whereIn('technology', $changedTechnologies)->distinct()->pluck('deployment_id')->map(fn (mixed $id): int => (int) $id)->all(),
            );
        }

        $this->components->info($sendPreNoticeAlerts->handle().' pre-notice cards queued.');

        $sendFindingAlerts->handle();

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
