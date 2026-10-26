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
#[Fillable(['team_id', 'dedupe_key', 'kind', 'payload', 'covers_until', 'attempts', 'sent_at', 'failed_at', 'last_error'])]
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
            'covers_until' => 'datetime',
            'attempts' => 'integer',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /**
     * The team's webhook, or the admin space's for a message without a team. Null when none is configured; an admin
     * webhook without the Google Chat prefix counts as none.
     */
    public function webhookUrl(): ?string
    {
        $url = $this->team_id === null ? config('psm.alerts.admin_webhook') : $this->team?->google_chat_webhook_url;

        return is_string($url) && str_starts_with($url, Team::GOOGLE_CHAT_WEBHOOK_PREFIX) ? $url : null;
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
