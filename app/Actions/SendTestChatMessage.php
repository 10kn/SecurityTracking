<?php

namespace App\Actions;

use App\Enums\ChatNotificationKind;
use App\Models\Project;
use App\Models\Team;
use App\Services\GoogleChatClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Str;

class SendTestChatMessage
{
    public function __construct(private GoogleChatClient $client) {}

    /**
     * Send a test message to the saved webhook of a team or of a project with its own, or to a candidate webhook
     * before it is saved.
     *
     * Every attempt on the saved webhook is logged in chat_notifications. A failed candidate is not logged,
     * so checking a mistyped URL does not mark the working webhook as failing.
     *
     * @return string|null The error shown to the admin, or null when Google Chat accepted the message.
     */
    public function handle(Team|Project $owner, ?string $candidateWebhookUrl = null): ?string
    {
        $webhookUrl = $candidateWebhookUrl ?? $owner->google_chat_webhook_url;

        if (blank($webhookUrl)) {
            return $owner instanceof Project ? __('Project chưa có webhook riêng.') : __('Team chưa có webhook.');
        }

        $message = [
            'text' => $owner instanceof Project
                ? __('Tin thử từ Security Manager: space này sẽ nhận cảnh báo bảo mật của project :project (team :team).', ['project' => $owner->name, 'team' => $owner->team->name])
                : __('Tin thử từ Security Manager: space này sẽ nhận cảnh báo bảo mật của team :team.', ['team' => $owner->name]),
        ];

        $error = $this->deliver($webhookUrl, $message);

        if ($candidateWebhookUrl === null || $error === null) {
            $owner->chatNotifications()->create([
                'team_id' => $owner instanceof Project ? $owner->team_id : $owner->id,
                'dedupe_key' => 'test:'.Str::uuid(),
                'kind' => ChatNotificationKind::Test,
                'payload' => $message,
                'attempts' => 1,
                'sent_at' => $error === null ? now() : null,
                'failed_at' => $error === null ? null : now(),
                'last_error' => $error,
            ]);
        }

        return $error;
    }

    /**
     * Send a test message to a webhook that belongs to no team, e.g. one of the admin's spaces. Nothing is logged.
     *
     * @return string|null The error shown to the admin, or null when Google Chat accepted the message.
     */
    public function toWebhook(string $webhookUrl, string $text): ?string
    {
        return $this->deliver($webhookUrl, ['text' => $text]);
    }

    /**
     * The error never contains the webhook URL, because the URL holds the space's key and token.
     *
     * @param  array<string, mixed>  $message
     */
    private function deliver(string $webhookUrl, array $message): ?string
    {
        try {
            $this->client->send($webhookUrl, $message);
        } catch (RequestException $exception) {
            return __('Google Chat trả lỗi HTTP :status.', ['status' => $exception->response->status()]);
        } catch (ConnectionException) {
            return __('Không kết nối được tới Google Chat.');
        }

        return null;
    }
}
