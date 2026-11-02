<?php

namespace App\Actions;

use App\Enums\ProjectEventType;
use App\Models\Project;
use App\Models\User;
use App\Support\IncidentLock;
use Illuminate\Support\Facades\DB;

/**
 * An admin pauses the monitoring of a project no longer maintained ("Ngừng theo dõi"), with the reason: from then on
 * no alert, update, digest or card tells its team about its services, whatever their severity or CISA KEV says. They
 * are still scanned, so their findings are up to date when it is resumed (see ResumeProjectMonitoring). Messages
 * already queued are still delivered.
 *
 * The IncidentLock of every technology the project runs is taken first, as the incident alerts take it, then the
 * project row, which the finding alerts and digests share-lock: an alert run either queues before the pause or sees
 * it.
 */
class PauseProjectMonitoring
{
    /**
     * @return bool Whether it was paused; false when it already was.
     */
    public function handle(User $user, Project $project, string $reason): bool
    {
        return DB::transaction(function () use ($user, $project, $reason): bool {
            IncidentLock::take($project->currentTechnologies());

            $project = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();

            if ($project->isPaused()) {
                return false;
            }

            $project->update(['monitoring_paused_at' => now(), 'monitoring_paused_by' => $user->id, 'monitoring_pause_reason' => $reason]);
            $project->events()->create(['type' => ProjectEventType::Paused, 'user_id' => $user->id, 'data' => ['reason' => $reason, 'user' => $user->name]]);

            return true;
        });
    }
}
