<?php

namespace App\Console\Commands;

use App\Actions\SendOsSecurityNews;
use App\Actions\SyncOsAdvisoryFeed;
use App\Enums\LinuxDistribution;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('psm:watch-os-advisories {--os=* : Operating systems to poll, e.g. "ubuntu"; all when omitted}')]
#[Description('Poll the security advisories of Amazon Linux, Ubuntu and Debian and send those that count to the security news space')]
class WatchOsAdvisories extends Command
{
    /**
     * A failing feed does not stop the others; what the others gave is still sent.
     */
    public function handle(SyncOsAdvisoryFeed $syncOsAdvisoryFeed, SendOsSecurityNews $sendOsSecurityNews): int
    {
        $systems = array_filter(
            LinuxDistribution::cases(),
            fn (LinuxDistribution $os): bool => $this->option('os') === [] || in_array($os->value, $this->option('os'), true),
        );

        $failures = 0;

        foreach ($systems as $os) {
            try {
                $advisories = $syncOsAdvisoryFeed->handle($os);
                $this->components->info("{$os->getLabel()}: ".count($advisories).' advisories, '.collect($advisories)->filter->wasRecentlyCreated->count().' new');
            } catch (Throwable $exception) {
                $failures++;
                report($exception);
                $this->components->error("{$os->getLabel()}: {$exception->getMessage()}");
            }
        }

        $this->components->info($sendOsSecurityNews->handle().' security news queued.');

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
