<?php

namespace App\Jobs;

use App\Actions\SendAdminAlert;
use App\Models\ChatNotification;
use App\Services\GoogleChatClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Traits\Localizable;
use Throwable;

/**
 * Delivers one Google Chat message (handover 5.5). Transient errors are retried with backoff; a message that cannot be
 * delivered, including one for a team without a webhook, is marked failed and reported to the admin space.
 *
 * Errors never contain the webhook URL, because the URL holds the space's key and token.
 */
class SendChatNotification implements ShouldQueue
{
    use Localizable, Queueable;

    public function __construct(public ChatNotification $notification) {}

    public function tries(): int
    {
        return config('psm.alerts.tries');
    }

    /**
     * Errors and admin alerts are written in the alert language, also when the job runs inside an admin's request.
     */
    public function handle(GoogleChatClient $client, SendAdminAlert $sendAdminAlert): void
    {
        $this->withLocale(config('psm.alerts.locale'), fn () => $this->deliver($client, $sendAdminAlert));
    }

    private function deliver(GoogleChatClient $client, SendAdminAlert $sendAdminAlert): void
    {
        $notification = $this->notification->refresh();

        if ($notification->sent_at !== null || $notification->failed_at !== null) {
            return;
        }

        $webhookUrl = $notification->webhookUrl();

        if ($webhookUrl === null) {
            $this->markFailed($sendAdminAlert, $notification->team_id === null ? __('Chưa cấu hình PSM_ADMIN_CHAT_WEBHOOK.') : __('Team chưa có webhook.'));

            return;
        }

        $notification->increment('attempts');

        try {
            $client->send($webhookUrl, $notification->payload);
        } catch (RequestException $exception) {
            $this->retryOrFail($sendAdminAlert, __('Google Chat trả lỗi HTTP :status.', ['status' => $exception->response->status()]), $this->isPermanent($exception));

            return;
        } catch (ConnectionException) {
            $this->retryOrFail($sendAdminAlert, __('Không kết nối được tới Google Chat.'), false);

            return;
        }

        $notification->update(['sent_at' => now(), 'last_error' => null]);
    }

    /**
     * The worker gave up, e.g. the job timed out on its last attempt.
     */
    public function failed(?Throwable $exception): void
    {
        $this->withLocale(config('psm.alerts.locale'), fn () => $this->markFailed(app(SendAdminAlert::class), $this->notification->last_error ?? __('Gửi tin thất bại.')));
    }

    private function retryOrFail(SendAdminAlert $sendAdminAlert, string $error, bool $isPermanent): void
    {
        if ($isPermanent || $this->attempts() >= $this->tries()) {
            $this->markFailed($sendAdminAlert, $error);

            return;
        }

        $this->notification->update(['last_error' => $error]);

        $backoff = config('psm.alerts.backoff_seconds');

        $this->release($backoff[min($this->attempts(), count($backoff)) - 1]);
    }

    /**
     * A client error other than a timeout or rate limit will not succeed on retry, e.g. the webhook was deleted.
     */
    private function isPermanent(RequestException $exception): bool
    {
        $status = $exception->response->status();

        return $status >= 400 && $status < 500 && ! in_array($status, [408, 429], true);
    }

    private function markFailed(SendAdminAlert $sendAdminAlert, string $error): void
    {
        $notification = $this->notification;

        if ($notification->failed_at !== null) {
            return;
        }

        $notification->update(['failed_at' => now(), 'last_error' => $error]);

        if ($notification->team_id === null) {
            Log::error('Could not deliver an alert to the admin Google Chat space.', ['notification_id' => $notification->id, 'error' => $error]);

            return;
        }

        $notification->loadMissing('team');

        $sendAdminAlert->handle(
            "undelivered:{$notification->id}",
            __('Không gửi được tin tới team :team', ['team' => $notification->team->name]),
            [
                __('Lỗi: :error', ['error' => $error]),
                __('Loại tin: :kind', ['kind' => $notification->kind->getLabel()]),
                __('Nội dung: :text', ['text' => $notification->payload['text'] ?? '—']),
            ],
        );
    }
}
