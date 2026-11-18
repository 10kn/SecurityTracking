<?php

namespace App\Console\Commands;

use App\Actions\SyncJpcertNews;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

#[Signature('psm:watch-jpcert')]
#[Description('Collect and classify JPCERT/CC news for the admin inbox')]
class WatchJpcertNews extends Command
{
    public function handle(SyncJpcertNews $sync): int
    {
        $result = Cache::lock('psm:watch-jpcert', 900)->get($sync->handle(...));

        if ($result === false) {
            $this->components->info('A JPCERT/CC sync is already running.');

            return self::SUCCESS;
        }

        $this->components->info("{$result['classified']} articles classified. {$result['queued']} admin messages queued.");

        if ($result['failed']) {
            $this->components->error('A JPCERT/CC source or classifier failed. Check source status.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
