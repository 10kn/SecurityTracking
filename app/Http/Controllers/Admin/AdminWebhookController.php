<?php

namespace App\Http\Controllers\Admin;

use App\Actions\SendTestChatMessage;
use App\Enums\AdminWebhook;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAdminWebhookRequest;
use App\Services\SystemSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * The admin's Google Chat spaces on the "Cài đặt hệ thống" screen, over the ones of the .env.
 */
class AdminWebhookController extends Controller
{
    /**
     * Sends a test message to the new URL first and saves it only when Google Chat accepts the message.
     */
    public function update(UpdateAdminWebhookRequest $request, AdminWebhook $webhook, SystemSettings $settings, SendTestChatMessage $sendTestChatMessage): RedirectResponse
    {
        $webhookUrl = $request->validated('google_chat_webhook_url');
        $error = $sendTestChatMessage->toWebhook($webhookUrl, $webhook->testMessage());

        if ($error !== null) {
            throw ValidationException::withMessages([
                'google_chat_webhook_url' => __('Gửi thử thất bại, webhook chưa được lưu: :error', ['error' => $error]),
            ]);
        }

        $settings->updateWebhook($webhook->settingName(), $webhookUrl, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Đã lưu webhook và gửi thử thành công.')]);

        return to_route('admin.settings.edit');
    }

    /**
     * Removes the webhook set on the screen, so the one of the .env (if any) is used again.
     */
    public function destroy(Request $request, AdminWebhook $webhook, SystemSettings $settings): RedirectResponse
    {
        if ($settings->webhookSource($webhook->settingName()) === 'settings') {
            $settings->updateWebhook($webhook->settingName(), null, $request->user());
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Đã dùng lại webhook trong .env.')]);

        return to_route('admin.settings.edit');
    }
}
