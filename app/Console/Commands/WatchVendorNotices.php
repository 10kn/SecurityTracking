<?php

namespace App\Console\Commands;

use App\Actions\SendPreNoticeAlerts;
use App\Actions\SendSecurityNews;
use App\Actions\SyncNoticeFeed;
use App\Enums\Technology;
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
     *
     * The classifier never delays an alert it could not change: every feed is polled and what the CVSS score or a KEV
     * listing makes Critical is sent first. Then the classifier reads the new and edited entries, and the rest is sent
     * with what it read, the vendor's words standing in for it where it failed (see SendPreNoticeAlerts). The admin's
     * security news space gets every pre-notice that counts last, once the classifier has read it (see SendSecurityNews).
     */
    public function handle(SyncNoticeFeed $syncNoticeFeed, SendPreNoticeAlerts $sendPreNoticeAlerts, SendSecurityNews $sendSecurityNews): int
    {
        $failures = 0;
        $polled = [];

        foreach (Technology::cases() as $technology) {
            $feed = $technology->releaseSourceLabel();

            try {
                $polled[$technology->value] = $syncNoticeFeed->poll($technology);
                $this->components->info("{$feed}: ".count($polled[$technology->value]).' pre-notices');
            } catch (Throwable $exception) {
                $failures++;
                report($exception);
                $this->components->error("{$feed}: {$exception->getMessage()}");
            }
        }

        $queued = $sendPreNoticeAlerts->handle(holdUnclassified: true);

        foreach ($polled as $technology => $entries) {
            $syncNoticeFeed->classify(Technology::from($technology), $entries);
        }

        $queued += $sendPreNoticeAlerts->handle();

        $this->components->info("{$queued} pre-notice cards queued.");
        $this->components->info($sendSecurityNews->handle().' security news queued.');

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
