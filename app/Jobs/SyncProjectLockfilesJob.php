<?php

namespace App\Jobs;

use App\Actions\SyncProjectLockfiles;
use App\Models\Project;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Reads a project's lockfiles from its repository outside the daily schedule: when an admin asks for it, or right
 * after the repository settings of the project or the directory of one of its services changed.
 */
class SyncProjectLockfilesJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public Project $project) {}

    public function uniqueId(): string
    {
        return (string) $this->project->id;
    }

    public function handle(SyncProjectLockfiles $syncProjectLockfiles): void
    {
        $syncProjectLockfiles->handle($this->project);
    }
}
