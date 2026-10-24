<?php

namespace App\Http\Controllers\Admin;

use App\Actions\SendTestChatMessage;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateTeamWebhookRequest;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class TeamWebhookController extends Controller
{
    /**
     * Sends a test message to the new URL first and saves it only when Google Chat accepts the message.
     */
    public function update(UpdateTeamWebhookRequest $request, Team $team, SendTestChatMessage $sendTestChatMessage): RedirectResponse
    {
        $webhookUrl = $request->validated('google_chat_webhook_url');
        $error = $sendTestChatMessage->handle($team, $webhookUrl);

        if ($error !== null) {
            throw ValidationException::withMessages([
                'google_chat_webhook_url' => __('Gửi thử thất bại, webhook chưa được lưu: :error', ['error' => $error]),
            ]);
        }

        $team->update(['google_chat_webhook_url' => $webhookUrl]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Đã lưu webhook và gửi thử thành công.')]);

        return to_route('admin.teams.edit', $team);
    }
}
