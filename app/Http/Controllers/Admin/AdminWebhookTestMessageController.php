<?php

namespace App\Http\Controllers\Admin;

use App\Actions\SendTestChatMessage;
use App\Enums\AdminWebhook;
use App\Http\Controllers\Controller;
use App\Services\SystemSettings;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class AdminWebhookTestMessageController extends Controller
{
    /**
     * Sends a test message to the webhook in use, set on the screen or in the .env.
     */
    public function store(AdminWebhook $webhook, SystemSettings $settings, SendTestChatMessage $sendTestChatMessage): RedirectResponse
    {
        $webhookUrl = $settings->current()[$webhook->settingName()];

        $error = $webhookUrl === null
            ? __('Chưa có webhook.')
            : $sendTestChatMessage->toWebhook($webhookUrl, $webhook->testMessage());

        Inertia::flash('toast', $error === null
            ? ['type' => 'success', 'message' => __('Gửi thử thành công.')]
            : ['type' => 'error', 'message' => __('Gửi thử thất bại'), 'description' => $error]);

        return to_route('admin.settings.edit');
    }
}
