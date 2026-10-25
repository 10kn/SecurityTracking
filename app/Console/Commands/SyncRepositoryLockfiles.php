<?php

namespace App\Console\Commands;

use App\Actions\SyncProjectLockfiles;
use App\Models\Project;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('psm:sync-lockfiles')]
#[Description('Read the lockfiles of every project that syncs them from its GitHub repository')]
class SyncRepositoryLockfiles extends Command
{
    /**
     * Handover D12: once a day. Services whose lockfiles changed are evaluated again by the queued listener, which
     * also sends the alerts of the findings that opened.
     */
    public function handle(SyncProjectLockfiles $syncProjectLockfiles): int
    {
        $projects = Project::where('syncs_lockfiles', true)->orderBy('id')->get();
        $failed = false;

        foreach ($projects as $project) {
            try {
                $syncProjectLockfiles->handle($project);
            } catch (Throwable $exception) {
                $failed = true;
                report($exception);
                $this->components->error("{$project->name}: {$exception->getMessage()}");
            }
        }

        $this->components->info("{$projects->count()} projects synced.");

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
