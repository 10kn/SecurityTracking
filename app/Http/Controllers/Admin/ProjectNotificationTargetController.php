<?php

namespace App\Http\Controllers\Admin;

use App\Enums\NotificationTarget;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateProjectNotificationTargetRequest;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class ProjectNotificationTargetController extends Controller
{
    /**
     * Moves the project's messages between its team's webhook and its own. Its own webhook stays saved when it goes
     * back to the team; threads of incidents open anew on the next message, wherever it goes.
     */
    public function update(UpdateProjectNotificationTargetRequest $request, Project $project): RedirectResponse
    {
        $target = NotificationTarget::from($request->validated('notification_target'));

        $project->update(['notification_target' => $target]);

        Inertia::flash('toast', ['type' => 'success', 'message' => $target === NotificationTarget::Project
            ? __('Thông báo của project sẽ gửi về webhook riêng.')
            : __('Thông báo của project sẽ gửi về team.')]);

        return to_route('admin.projects.edit', $project);
    }
}
