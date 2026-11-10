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
     * cannot be queued. A message of an incident thread chooses again whether it replies in the thread or opens it,
     * as the thread may have been opened, or its opener failed, meanwhile; it keeps its request id, so Google Chat
     * does not post it twice if the failed attempt did get through. An alert of an incident thread to a team an
     * admin's "Không" still rules out is not taken back: it stays failed, with that reason.
     */
    public function handle(): int
    {
        $notification = ChatNotification::find($this->argument('notification'));
        $failedAt = $notification?->failed_at;

        if ($failedAt !== null && $notification->sent_at === null && $notification->isWithheldByAssessment()) {
            $notification->withhold();
            $this->components->error(__('Không gửi lại: admin đã đánh giá sự kiện không ảnh hưởng tới team (Không).'));

            return self::FAILURE;
        }

        $isTaken = $failedAt !== null && ChatNotification::whereKey($notification->id)
            ->where('failed_at', $failedAt)
            ->whereNull('sent_at')
            ->update(['failed_at' => null, 'attempts' => 0, 'sent_as' => null]) === 1;

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
