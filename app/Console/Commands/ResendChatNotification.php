<?php

namespace App\Console\Commands;

use App\Jobs\SendChatNotification;
use App\Models\ChatNotification;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('psm:resend-notification {notification : The id the admin alert gives}')]
#[Description('Queue a Google Chat message that could not be delivered again, e.g. once the team webhook is fixed')]
class ResendChatNotification extends Command
{
    /**
     * A message that failed for good is never retried on its own, so it is not sent twice by mistake. A message that
     * was delivered is not sent again. Only one run takes a failed message back, and it is marked failed again when it
     * cannot be queued.
     */
    public function handle(): int
    {
        $notification = ChatNotification::find($this->argument('notification'));
        $failedAt = $notification?->failed_at;

        $isTaken = $failedAt !== null && ChatNotification::whereKey($notification->id)
            ->where('failed_at', $failedAt)
            ->whereNull('sent_at')
            ->update(['failed_at' => null, 'attempts' => 0]) === 1;

        if (! $isTaken) {
            $this->components->error(__('Không có tin gửi lỗi với id này.'));

            return self::FAILURE;
        }

        try {
            SendChatNotification::dispatch($notification->refresh());
        } catch (Throwable $exception) {
            $notification->update(['failed_at' => $failedAt]);

            throw $exception;
        }

        $this->components->info(__('Đã đưa tin vào hàng đợi để gửi lại.'));

        return self::SUCCESS;
    }
}
