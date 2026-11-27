<?php

namespace App\Actions;

use App\Enums\ChatNotificationKind;
use App\Jobs\SendChatNotification;
use App\Models\ChatNotification;
use App\Models\Team;
use App\Support\ChatRecipient;
use Illuminate\Support\Facades\Bus;

/**
 * Records a Google Chat message and queues its delivery. The dedupe key is unique, so a message is never queued twice,
 * even when two runs race. With `$sendNow` it is delivered in this process instead, for alerts that must not depend
 * on the queue worker, such as the one saying messages are stuck in the queue. Such a message gets no retry from a
 * worker: the caller tries it again (see CheckSourceHeartbeats).
 */
class QueueChatNotification
{
    /**
     * @param  ChatRecipient|Team|null  $recipient  A team's or a project's own webhook (see ChatRecipient); null sends to the admin space.
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $attributes  Other columns to record, e.g. `covers_until`.
     * @return ChatNotification|null The new message, or null when one with the same dedupe key already exists.
     */
    public function handle(ChatRecipient|Team|null $recipient, ChatNotificationKind $kind, string $dedupeKey, array $payload, bool $sendNow = false, array $attributes = []): ?ChatNotification
    {
        $recipient = $recipient instanceof Team ? ChatRecipient::team($recipient) : $recipient;

        $notification = ChatNotification::createOrFirst(['dedupe_key' => $dedupeKey], [
            ...$attributes,
            'team_id' => $recipient?->team->id,
            'project_id' => $recipient?->projectId(),
            'kind' => $kind,
            'payload' => $payload,
        ]);

        if (! $notification->wasRecentlyCreated) {
            return null;
        }

        if ($sendNow) {
            Bus::dispatchNow(new SendChatNotification($notification));

            return $notification;
        }

        SendChatNotification::dispatch($notification)->afterCommit();

        return $notification;
    }
}
