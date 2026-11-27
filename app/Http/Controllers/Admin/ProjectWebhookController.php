<?php

namespace App\Http\Controllers\Admin;

use App\Actions\SendTestChatMessage;
use App\Enums\NotificationTarget;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateProjectWebhookRequest;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ProjectWebhookController extends Controller
{
    /**
     * Sends a test message to the new URL first and saves it only when Google Chat accepts the message. Saving it also
     * moves the project's messages to it, away from its team.
     */
    public function update(UpdateProjectWebhookRequest $request, Project $project, SendTestChatMessage $sendTestChatMessage): RedirectResponse
    {
        $webhookUrl = $request->validated('google_chat_webhook_url');
        $error = $sendTestChatMessage->handle($project, $webhookUrl);

        if ($error !== null) {
            throw ValidationException::withMessages([
                'google_chat_webhook_url' => __('Gửi thử thất bại, webhook chưa được lưu: :error', ['error' => $error]),
            ]);
        }

        $project->update(['google_chat_webhook_url' => $webhookUrl, 'notification_target' => NotificationTarget::Project]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Đã lưu webhook và gửi thử thành công.')]);

        return to_route('admin.projects.edit', $project);
    }
}
