<?php

namespace App\Http\Controllers\Admin;

use App\Actions\SendTestChatMessage;
use App\Http\Controllers\Controller;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class ProjectTestMessageController extends Controller
{
    public function store(Project $project, SendTestChatMessage $sendTestChatMessage): RedirectResponse
    {
        $error = $sendTestChatMessage->handle($project);

        Inertia::flash('toast', $error === null
            ? ['type' => 'success', 'message' => __('Gửi thử thành công.')]
            : ['type' => 'error', 'message' => __('Gửi thử thất bại'), 'description' => $error]);

        return to_route('admin.projects.edit', $project);
    }
}
