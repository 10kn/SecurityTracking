<?php

namespace App\Console\Commands;

use App\Actions\SendDailyDigest;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('psm:send-digest')]
#[Description('Send each team its daily Google Chat digest of findings and runtimes; teams with nothing to report get none')]
class SendDailyDigests extends Command
{
    public function handle(SendDailyDigest $sendDailyDigest): int
    {
        $this->components->info($sendDailyDigest->handle().' digests queued.');

        return self::SUCCESS;
    }
}
