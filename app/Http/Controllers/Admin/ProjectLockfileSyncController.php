<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SyncProjectLockfilesJob;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class ProjectLockfileSyncController extends Controller
{
    public function store(Project $project): RedirectResponse
    {
        abort_unless($project->syncs_lockfiles, 404);

        SyncProjectLockfilesJob::dispatch($project);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Đã đưa :project vào hàng đợi lấy lockfile.', ['project' => $project->name])]);

        return back();
    }
}
