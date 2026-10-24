<?php

namespace App\Actions;

use App\Enums\ChatNotificationKind;
use App\Jobs\SendChatNotification;
use App\Models\ChatNotification;
use App\Models\Team;

/**
 * Records a Google Chat message and queues its delivery. The dedupe key is unique, so a message is never queued twice,
 * even when two runs race.
 */
class QueueChatNotification
{
    /**
     * @param  Team|null  $team  Null sends to the admin space.
     * @param  array<string, mixed>  $payload
     * @return ChatNotification|null The new message, or null when one with the same dedupe key already exists.
     */
    public function handle(?Team $team, ChatNotificationKind $kind, string $dedupeKey, array $payload): ?ChatNotification
    {
        $notification = ChatNotification::createOrFirst(['dedupe_key' => $dedupeKey], [
            'team_id' => $team?->id,
            'kind' => $kind,
            'payload' => $payload,
        ]);

        if (! $notification->wasRecentlyCreated) {
            return null;
        }

        SendChatNotification::dispatch($notification)->afterCommit();

        return $notification;
    }
}
