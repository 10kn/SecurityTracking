<?php

namespace App\Models;

use App\Enums\ChatNotificationKind;
use Database\Factories\ChatNotificationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Log of Google Chat messages. A null team means the message goes to the admin space.
 *
 * Named chat_notifications so it does not clash with Laravel's database `notifications` table.
 */
#[Fillable(['team_id', 'dedupe_key', 'kind', 'payload', 'attempts', 'sent_at', 'failed_at', 'last_error'])]
class ChatNotification extends Model
{
    /** @use HasFactory<ChatNotificationFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ChatNotificationKind::class,
            'payload' => 'array',
            'attempts' => 'integer',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
