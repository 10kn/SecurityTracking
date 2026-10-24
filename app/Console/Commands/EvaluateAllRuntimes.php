<?php

namespace App\Console\Commands;

use App\Actions\EvaluateRuntimes;
use App\Actions\SendFindingAlerts;
use App\Models\Runtime;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

#[Signature('psm:evaluate-runtimes')]
#[Description('Re-evaluate every runtime, e.g. after midnight when a branch may have reached its end-of-life date')]
class EvaluateAllRuntimes extends Command
{
    public function handle(EvaluateRuntimes $evaluateRuntimes, SendFindingAlerts $sendFindingAlerts): int
    {
        Runtime::with('technology')->chunkById(200, fn (Collection $runtimes) => $evaluateRuntimes->handle($runtimes));

        $sendFindingAlerts->handle();

        $this->components->info(Runtime::count().' runtimes evaluated.');

        return self::SUCCESS;
    }
}
