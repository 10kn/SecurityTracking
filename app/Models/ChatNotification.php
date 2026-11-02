<?php

namespace App\Models;

use App\Enums\ChatNotificationKind;
use App\Enums\ChatThreadRole;
use Database\Factories\ChatNotificationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Log of Google Chat messages. A null team means the message goes to the admin space.
 *
 * Named chat_notifications so it does not clash with Laravel's database `notifications` table.
 *
 * A message about an incident belongs to the thread of its team (`incident_team_id`, `thread_key`, `generation`):
 * `thread_role` is what it was queued as, `sent_as` what it is sent as, fixed before its first HTTP attempt (a reply
 * whose thread could not be opened sends `opening_payload` and opens it). `attempts` counts HTTP attempts only, and
 * `request_id` stays the same across them, so Google Chat never posts it twice.
 */
#[Fillable(['team_id', 'incident_team_id', 'thread_key', 'generation', 'thread_role', 'sent_as', 'dedupe_key', 'request_id', 'kind', 'requested_by', 'payload', 'opening_payload', 'covers_until', 'attempts', 'sent_at', 'google_message_name', 'google_thread_name', 'failed_at', 'last_error'])]
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
            'thread_role' => ChatThreadRole::class,
            'sent_as' => ChatThreadRole::class,
            'generation' => 'integer',
            'payload' => 'array',
            'opening_payload' => 'array',
            'covers_until' => 'datetime',
            'attempts' => 'integer',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /**
     * Every message gets its request id when it is recorded.
     */
    protected static function booted(): void
    {
        static::creating(function (ChatNotification $notification): void {
            $notification->request_id ??= (string) Str::uuid();
        });
    }

    /**
     * The payload the message is sent with: the full card when it opens its thread instead of replying in it.
     *
     * @return array<string, mixed>
     */
    public function payloadToSend(): array
    {
        return $this->sent_as === ChatThreadRole::Opener && $this->thread_role === ChatThreadRole::Reply && $this->opening_payload !== null ? $this->opening_payload : $this->payload;
    }

    /**
     * The team's webhook, or the admin space's for a message without a team: security news goes to its own space,
     * or to the admin space when none is set. Null when none is configured; an admin webhook without the Google Chat
     * prefix counts as none.
     */
    public function webhookUrl(): ?string
    {
        $url = match (true) {
            $this->team_id !== null => $this->team?->google_chat_webhook_url,
            $this->kind === ChatNotificationKind::SecurityNews => self::securityNewsWebhookUrl(),
            default => config('psm.alerts.admin_webhook'),
        };

        return is_string($url) && str_starts_with($url, Team::GOOGLE_CHAT_WEBHOOK_PREFIX) ? $url : null;
    }

    /**
     * The security news space's webhook, the admin space's when `psm.alerts.news_webhook` is not set.
     */
    public static function securityNewsWebhookUrl(): ?string
    {
        return filled(config('psm.alerts.news_webhook')) ? config('psm.alerts.news_webhook') : config('psm.alerts.admin_webhook');
    }

    /**
     * Whether security news has a space of its own, apart from the admin space's system alerts.
     */
    public static function hasSecurityNewsSpace(): bool
    {
        return filled(config('psm.alerts.news_webhook')) && config('psm.alerts.news_webhook') !== config('psm.alerts.admin_webhook');
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return BelongsTo<IncidentTeam, $this>
     */
    public function incidentTeam(): BelongsTo
    {
        return $this->belongsTo(IncidentTeam::class);
    }
}
