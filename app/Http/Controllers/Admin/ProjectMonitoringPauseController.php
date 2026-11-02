<?php

namespace App\Http\Controllers\Admin;

use App\Actions\PauseProjectMonitoring;
use App\Actions\ResumeProjectMonitoring;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PauseProjectRequest;
use App\Http\Requests\Admin\ResumeProjectRequest;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class ProjectMonitoringPauseController extends Controller
{
    /**
     * Pauses the monitoring of the project ("Ngừng theo dõi").
     */
    public function store(PauseProjectRequest $request, Project $project, PauseProjectMonitoring $pauseProjectMonitoring): RedirectResponse
    {
        $isPaused = $pauseProjectMonitoring->handle($request->user(), $project, $request->reason());

        Inertia::flash('toast', $isPaused
            ? ['type' => 'success', 'message' => __('Đã ngừng theo dõi project :project.', ['project' => $project->name]), 'description' => __('Team không còn nhận thông báo về project này cho tới khi mở lại.')]
            : ['type' => 'error', 'message' => __('Project :project đã ngừng theo dõi từ trước.', ['project' => $project->name])]);

        return to_route('admin.projects.show', $project);
    }

    /**
     * Resumes the monitoring of the project, telling the team what it missed or not.
     */
    public function destroy(ResumeProjectRequest $request, Project $project, ResumeProjectMonitoring $resumeProjectMonitoring): RedirectResponse
    {
        $notify = $request->boolean('notify');
        $isResumed = $resumeProjectMonitoring->handle($request->user(), $project, $notify, $request->note());

        Inertia::flash('toast', match (true) {
            ! $isResumed => ['type' => 'error', 'message' => __('Project :project đang được theo dõi.', ['project' => $project->name])],
            $notify => ['type' => 'success', 'message' => __('Đã theo dõi lại project :project.', ['project' => $project->name]), 'description' => __('Team sẽ nhận một tin tóm tắt tình trạng hiện tại.')],
            default => ['type' => 'success', 'message' => __('Đã theo dõi lại project :project.', ['project' => $project->name]), 'description' => __('Những gì tồn đọng được đánh dấu đã đọc; team chỉ nhận thông báo về thay đổi mới.')],
        });

        return to_route('admin.projects.show', $project);
    }
}
