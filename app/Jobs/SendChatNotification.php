<?php

namespace App\Jobs;

use App\Actions\SendAdminAlert;
use App\Enums\ChatNotificationKind;
use App\Enums\ChatThreadRole;
use App\Models\ChatNotification;
use App\Models\Incident;
use App\Models\IncidentTeam;
use App\Models\Team;
use App\Services\GoogleChatClient;
use App\Support\IncidentLock;
use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Illuminate\Support\Traits\Localizable;
use Throwable;

/**
 * Delivers one Google Chat message (handover 5.5). Transient errors are retried with backoff, up to
 * `psm.alerts.tries` HTTP attempts within `psm.alerts.retry_hours`; a message that cannot be delivered, including one
 * for a team without a webhook, is marked failed and reported to the admin space, which can send it again with
 * psm:resend-notification. Security news that cannot be delivered to its own space is reported to the admin space too.
 *
 * Messages to one space go one at a time, `psm.alerts.space_interval_seconds` apart, whatever their kind: a job that
 * would send too early, or while another message of the space is being sent, goes back to the queue without using a
 * try. A 429 holds the whole space for its Retry-After. Run without a queue worker (sync, or sent right away), the job
 * cannot be released: it sleeps without the lock and tries again, and marks the message failed (to be sent again with
 * psm:resend-notification) when the space stays busy.
 *
 * A message of an incident thread carries its `threadKey`, so Google Chat replies in the thread or opens it. A reply
 * waits for the card that opens its thread, up to `psm.alerts.opener_wait_minutes`; when that card failed, came back
 * without a thread or is still not sent, the reply sends its full card instead and opens the thread itself. That
 * choice is made under the space lock and the thread's row lock, and fixed before the first HTTP attempt. Where the
 * message goes is checked again before every attempt: a team whose webhook changed, or an incident merged into
 * another, sends the full card to the thread it has now (see routeThreadMessage()).
 *
 * Errors never contain the webhook URL, because the URL holds the space's key and token.
 */
class SendChatNotification implements ShouldQueue
{
    use Localizable, Queueable;

    /**
     * Alerts to send once the space lock is released, so an alert to the same space never waits on it.
     *
     * @var list<Closure(): void>
     */
    private array $afterUnlock = [];

    private bool $isHoldingLock = false;

    /**
     * The space's next turn a job that cannot be released slept for: it is that job's turn while no other message
     * took it meanwhile.
     */
    private ?int $awaitedTurn = null;

    public function __construct(public ChatNotification $notification) {}

    /**
     * A fixed deadline, set when the job is queued: waiting for its turn in the space does not count as a try, so the
     * job can go back to the queue any number of times until then.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(config('psm.alerts.retry_hours'));
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
            $this->markFailed($sendAdminAlert, match (true) {
                $notification->team_id !== null => __('Team chưa có webhook.'),
                $notification->kind === ChatNotificationKind::SecurityNews => __('Chưa cấu hình PSM_ADMIN_NEWS_WEBHOOK hay PSM_ADMIN_CHAT_WEBHOOK.'),
                default => __('Chưa cấu hình PSM_ADMIN_CHAT_WEBHOOK.'),
            });

            return;
        }

        $space = (string) Team::googleChatSpaceOf($webhookUrl);
        $lock = Cache::lock("chat-space:{$space}", 30);

        for ($round = 0; $round < 5; $round++) {
            if (! $this->acquire($lock)) {
                if (! $this->canRelease()) {
                    $this->markFailed($sendAdminAlert, __('Space Google Chat đang bận, chưa gửi được.'));
                }

                return;
            }

            $this->isHoldingLock = true;

            try {
                $waitMilliseconds = $this->deliverInTurn($client, $sendAdminAlert, $notification, $webhookUrl, $space);
            } finally {
                $lock->release();
                $this->isHoldingLock = false;
            }

            $this->flushAfterUnlock();

            if ($waitMilliseconds === 0) {
                return;
            }

            Sleep::for($waitMilliseconds)->milliseconds();
        }

        $this->markFailed($sendAdminAlert, __('Space Google Chat đang bận, chưa gửi được.'));
    }

    private function flushAfterUnlock(): void
    {
        $alerts = $this->afterUnlock;
        $this->afterUnlock = [];

        foreach ($alerts as $alert) {
            $alert();
        }
    }

    /**
     * Holding the space lock: waits for the space's next turn, checks where the message of a thread goes now, then
     * sends. A job that cannot be released does not wait holding the lock: it gets the time to wait, sleeps without
     * the lock and comes back.
     *
     * @return int Milliseconds to sleep before trying again, for a job that cannot be released; 0 when done.
     */
    private function deliverInTurn(GoogleChatClient $client, SendAdminAlert $sendAdminAlert, ChatNotification $notification, string $webhookUrl, string $space): int
    {
        $notification->refresh();

        if ($notification->sent_at !== null || $notification->failed_at !== null) {
            return 0;
        }

        $wait = $this->waitForTurn($space);

        if ($wait !== null) {
            return $wait;
        }

        if ($notification->thread_key !== null) {
            try {
                $waitSeconds = $this->routeThreadMessage($notification, $webhookUrl);
            } catch (QueryException $exception) {
                if (! $this->isLockTimeout($exception)) {
                    throw $exception;
                }

                if (! $this->canRelease()) {
                    return 1000;
                }

                $this->release(5);

                return 0;
            }

            if ($waitSeconds > 0) {
                $this->release($waitSeconds);

                return 0;
            }

            $wait = $this->waitForTurn($space);

            if ($wait !== null) {
                return $wait;
            }
        }

        $notification->increment('attempts');
        $this->holdSpace($space, config('psm.alerts.space_interval_seconds'));

        try {
            $answer = $client->send($webhookUrl, $this->body($notification), $this->query($notification));
        } catch (RequestException $exception) {
            $this->retryOrFail($sendAdminAlert, __('Google Chat trả lỗi HTTP :status.', ['status' => $exception->response->status()]), $this->isPermanent($exception), $this->retryAfter($exception, $space));

            return 0;
        } catch (ConnectionException) {
            $this->retryOrFail($sendAdminAlert, __('Không kết nối được tới Google Chat.'), false);

            return 0;
        }

        $this->recordDelivery($sendAdminAlert, $notification, $answer, $space);

        return 0;
    }

    /**
     * Null when it is the space's turn now. Otherwise a queued job goes back to the queue until then (0), and one that
     * cannot be released gets the milliseconds to sleep. Checked again right before the HTTP call, as routing a thread
     * message may have waited for the incidents' lock.
     */
    private function waitForTurn(string $space): ?int
    {
        $turn = (int) Cache::get("chat-space-next:{$space}", 0);
        $waitMilliseconds = $turn === $this->awaitedTurn ? 0 : max(0, $turn - (int) now()->getPreciseTimestamp(3));

        if ($waitMilliseconds === 0) {
            return null;
        }

        if (! $this->canRelease()) {
            $this->awaitedTurn = $turn;

            return $waitMilliseconds;
        }

        $this->release((int) ceil($waitMilliseconds / 1000));

        return 0;
    }

    /**
     * Before every attempt, under the thread's row lock: the message goes to the row of the incident its own was merged
     * into, and the thread follows the team to the webhook it posts to now (see IncidentTeam::moveTo()). A message whose
     * thread changed since it was queued moves to the current one.
     *
     * Its part is fixed once, before its first attempt: the opener, or a reply. A reply whose opener is still waiting
     * to be sent waits too, unless the job cannot be released or `psm.alerts.opener_wait_minutes` passed since it was
     * queued; otherwise it opens the thread with its full card. A message whose thread changed after its part was fixed
     * sends its full card instead, as the opener of the new thread unless that one is already open.
     *
     * @return int Seconds to wait for the opener; 0 once the part is fixed.
     */
    private function routeThreadMessage(ChatNotification $notification, string $webhookUrl): int
    {
        return DB::transaction(function () use ($notification, $webhookUrl): int {
            $this->lockIncidents($notification);

            $row = IncidentTeam::whereKey($notification->incident_team_id)->lockForUpdate()->first();

            if ($row === null) {
                if ($notification->sent_as === null) {
                    $notification->update(['sent_as' => $notification->opening_payload === null ? $notification->thread_role : ChatThreadRole::Opener]);
                }

                return 0;
            }

            $thread = $row->canonical();
            $thread->moveTo($webhookUrl);
            $isMoved = $notification->thread_key !== $thread->thread_key;
            $notification->incident_team_id = $thread->id;

            if ($isMoved) {
                $notification->fill(['generation' => $thread->generation, 'thread_key' => $thread->thread_key]);
            }

            $opener = $thread->opening_notification_id === null ? null : ChatNotification::find($thread->opening_notification_id);
            $isOpen = $opener !== null && $opener->sent_at !== null && $thread->thread_name !== null;

            if ($notification->sent_as !== null) {
                if ($isMoved) {
                    if (! $isOpen && ! $opener?->is($notification)) {
                        $thread->update(['opening_notification_id' => $notification->id]);
                    }

                    $notification->sent_as = ChatThreadRole::Opener;
                }

                $notification->save();

                return 0;
            }

            $waitUntil = $notification->created_at->copy()->addMinutes(config('psm.alerts.opener_wait_minutes'));

            $role = match (true) {
                $notification->thread_role === ChatThreadRole::Opener, $opener?->is($notification) => ChatThreadRole::Opener,
                $isOpen => ChatThreadRole::Reply,
                $opener !== null && $opener->sent_at === null && $opener->failed_at === null && $this->canRelease() && now()->lt($waitUntil) => null,
                default => ChatThreadRole::Opener,
            };

            if ($role === null) {
                $notification->save();

                return (int) max(1, min(15, (int) ceil(now()->diffInSeconds($waitUntil))));
            }

            $isOpenedByAnother = $opener !== null && ! $opener->is($notification) && (
                $isOpen || ($notification->thread_role === ChatThreadRole::Opener && $opener->sent_at === null && $opener->failed_at === null)
            );

            if ($role === ChatThreadRole::Opener && ! $isOpenedByAnother && ! $opener?->is($notification)) {
                $thread->update(['opening_notification_id' => $notification->id]);
            }

            $notification->sent_as = $role;
            $notification->save();

            return 0;
        });
    }

    /**
     * Takes the IncidentLock of the thread's technology before any thread row is locked, as merges and alerts do, so
     * following a merge never waits on them in the opposite order. Incidents are only merged within a technology. The
     * wait is bounded by `psm.alerts.incident_lock_wait_ms`, well under the 30 second lease of the space lock: a job
     * that times out goes back to the queue (or, run without a queue worker, counts it as a try for the space lock).
     */
    private function lockIncidents(ChatNotification $notification): void
    {
        $technology = Incident::whereKey(IncidentTeam::whereKey($notification->incident_team_id)->select('incident_id'))->first()?->technology;

        if ($technology !== null) {
            DB::statement('set local lock_timeout = '.max(1, (int) config('psm.alerts.incident_lock_wait_ms')));
            IncidentLock::take([$technology]);
        }
    }

    /**
     * PostgreSQL gave up waiting for a lock (`lock_timeout`).
     */
    private function isLockTimeout(QueryException $exception): bool
    {
        return $exception->getCode() === '55P03';
    }

    /**
     * The payload, with the thread key of a message of an incident thread.
     *
     * @return array<string, mixed>
     */
    private function body(ChatNotification $notification): array
    {
        $payload = $notification->payloadToSend();

        return $notification->thread_key === null ? $payload : [...$payload, 'thread' => ['threadKey' => $notification->thread_key]];
    }

    /**
     * Google Chat webhooks reject a `requestId` ("Request Id is not supported for webhooks"), so nothing makes a retry
     * idempotent: a timeout after Google posted the message posts it again.
     *
     * @return array<string, string>
     */
    private function query(ChatNotification $notification): array
    {
        return $notification->thread_key === null ? [] : ['messageReplyOption' => 'REPLY_MESSAGE_FALLBACK_TO_NEW_THREAD'];
    }

    /**
     * Records the names Google Chat returned. The thread is ready once a message of it came back with the thread's
     * name; a message of a thread that came back without one is sent, but leaves the thread to be opened by the next
     * important update, and the admin hears about it once per space and day.
     *
     * @param  array<string, mixed>  $answer
     */
    private function recordDelivery(SendAdminAlert $sendAdminAlert, ChatNotification $notification, array $answer, string $space): void
    {
        $messageName = is_string($answer['name'] ?? null) ? $answer['name'] : null;
        $threadName = is_string($answer['thread']['name'] ?? null) ? $answer['thread']['name'] : null;

        $notification->update(['sent_at' => now(), 'last_error' => null, 'google_message_name' => $messageName, 'google_thread_name' => $threadName]);

        if ($notification->thread_key === null) {
            return;
        }

        if ($threadName !== null) {
            try {
                DB::transaction(function () use ($notification, $threadName): void {
                    $this->lockIncidents($notification);

                    IncidentTeam::where('thread_key', $notification->thread_key)->whereNull('thread_name')->update(['thread_name' => $threadName, 'updated_at' => now()]);
                });
            } catch (QueryException $exception) {
                if (! $this->isLockTimeout($exception)) {
                    throw $exception;
                }

                Log::warning('Could not record the Google Chat thread of a delivered message: the incidents stayed locked.', ['notification_id' => $notification->id]);
            }

            return;
        }

        if ($notification->team_id === null) {
            return;
        }

        $notification->loadMissing('team');

        $this->afterUnlock[] = fn () => $sendAdminAlert->handle(
            "thread-missing:{$space}:".now(config('psm.digest.timezone'))->toDateString(),
            __('Google Chat không trả về thread'),
            [
                __('Team: :team', ['team' => $notification->team->name]),
                __('Tin đã gửi nhưng không mở được thread; cập nhật quan trọng tiếp theo sẽ gửi card đầy đủ.'),
                __('Kiểm tra webhook của space có hỗ trợ thread không.'),
            ],
        );
    }

    /**
     * The worker gave up, e.g. the retry deadline passed or the job timed out on its last attempt.
     */
    public function failed(?Throwable $exception): void
    {
        $this->withLocale(config('psm.alerts.locale'), fn () => $this->markFailed(app(SendAdminAlert::class), $this->notification->refresh()->last_error ?? __('Gửi tin thất bại.')));
    }

    private function retryOrFail(SendAdminAlert $sendAdminAlert, string $error, bool $isPermanent, ?int $retryAfter = null): void
    {
        $attempts = $this->notification->attempts;

        if ($isPermanent || $attempts >= config('psm.alerts.tries')) {
            $this->markFailed($sendAdminAlert, $error);

            return;
        }

        $this->notification->update(['last_error' => $error]);

        $backoff = config('psm.alerts.backoff_seconds');

        $this->release($retryAfter ?? $backoff[min($attempts, count($backoff)) - 1]);
    }

    /**
     * A client error other than a timeout or rate limit will not succeed on retry, e.g. the webhook was deleted.
     */
    private function isPermanent(RequestException $exception): bool
    {
        $status = $exception->response->status();

        return $status >= 400 && $status < 500 && ! in_array($status, [408, 429], true);
    }

    /**
     * For a 429, the seconds Google Chat asks to wait (a number or an HTTP date) plus a few seconds of jitter, so the
     * messages waiting for the space do not all come back at once. The whole space waits that long.
     */
    private function retryAfter(RequestException $exception, string $space): ?int
    {
        if ($exception->response->status() !== 429) {
            return null;
        }

        $header = trim((string) $exception->response->header('Retry-After'));

        $seconds = match (true) {
            $header === '' => null,
            ctype_digit($header) => (int) $header,
            default => rescue(fn (): int => (int) max(0, ceil(now()->diffInSeconds(Carbon::parse($header), false))), null, false),
        };

        if ($seconds === null) {
            return null;
        }

        $this->holdSpace($space, $seconds);

        return max(1, $seconds) + random_int(0, 3);
    }

    /**
     * Keeps the space for the next message no earlier than `$seconds` from now.
     */
    private function holdSpace(string $space, int|float $seconds): void
    {
        $next = (int) now()->getPreciseTimestamp(3) + (int) round($seconds * 1000);

        Cache::put("chat-space-next:{$space}", max($next, (int) Cache::get("chat-space-next:{$space}", 0)), now()->addHours(2));
    }

    /**
     * Takes the space lock. A queued job that finds it taken comes back in a second; one that cannot be released
     * waits for it about ten seconds (see deliver(), which then marks the message failed, to be sent again).
     */
    private function acquire(Lock $lock): bool
    {
        for ($try = 0; $try <= 40; $try++) {
            if ($lock->get()) {
                return true;
            }

            if ($this->canRelease()) {
                $this->release(1);

                return false;
            }

            Sleep::for(250)->milliseconds();
        }

        return false;
    }

    /**
     * Whether the job runs on a queue that can take it back: not run right away, nor on the sync connection.
     */
    private function canRelease(): bool
    {
        return $this->job !== null && ! $this->job instanceof SyncJob;
    }

    private function markFailed(SendAdminAlert $sendAdminAlert, string $error): void
    {
        $notification = $this->notification;

        if ($notification->failed_at !== null) {
            return;
        }

        $notification->update(['failed_at' => now(), 'last_error' => $error]);

        if ($notification->team_id === null && $notification->kind === ChatNotificationKind::SecurityNews && ChatNotification::hasSecurityNewsSpace()) {
            $this->afterUnlock[] = fn () => $sendAdminAlert->handle(
                'news-undelivered:'.now(config('psm.digest.timezone'))->toDateString(),
                __('Không gửi được tin bảo mật tới space tin'),
                [
                    __('Lỗi: :error', ['error' => $error]),
                    __('Nội dung: :text', ['text' => $notification->payload['text'] ?? '—']),
                    __('Kiểm tra PSM_ADMIN_NEWS_WEBHOOK rồi gửi lại: php artisan psm:resend-notification :id', ['id' => $notification->id]),
                ],
            );

            if (! $this->isHoldingLock) {
                $this->flushAfterUnlock();
            }

            return;
        }

        if ($notification->team_id === null) {
            Log::error('Could not deliver an alert to the admin Google Chat space.', ['notification_id' => $notification->id, 'error' => $error]);

            return;
        }

        $notification->loadMissing('team');

        $this->afterUnlock[] = fn () => $sendAdminAlert->handle(
            "undelivered:{$notification->id}:{$notification->failed_at->getTimestamp()}",
            __('Không gửi được tin tới team :team', ['team' => $notification->team->name]),
            [
                __('Lỗi: :error', ['error' => $error]),
                __('Loại tin: :kind', ['kind' => $notification->kind->getLabel()]),
                __('Nội dung: :text', ['text' => $notification->payload['text'] ?? '—']),
                __('Sửa lỗi rồi gửi lại: php artisan psm:resend-notification :id', ['id' => $notification->id]),
            ],
        );

        if (! $this->isHoldingLock) {
            $this->flushAfterUnlock();
        }
    }
}
