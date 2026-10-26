<?php

namespace App\Models;

use App\Enums\GoogleChatStatus;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

#[Fillable(['name', 'slug', 'google_chat_webhook_url', 'escalation_email'])]
#[Hidden(['google_chat_webhook_url'])]
class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use HasFactory;

    /**
     * Every Google Chat incoming webhook starts with this prefix. It also pins the host, which guards against SSRF.
     */
    public const string GOOGLE_CHAT_WEBHOOK_PREFIX = 'https://chat.googleapis.com/v1/spaces/';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'google_chat_webhook_url' => 'encrypted',
        ];
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    /**
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * @return HasMany<Finding, $this>
     */
    public function findings(): HasMany
    {
        return $this->hasMany(Finding::class);
    }

    /**
     * @return HasMany<ChatNotification, $this>
     */
    public function chatNotifications(): HasMany
    {
        return $this->hasMany(ChatNotification::class);
    }

    /**
     * The latest message that was delivered or gave up; one still being retried does not count yet.
     *
     * @return HasOne<ChatNotification, $this>
     */
    public function latestChatNotification(): HasOne
    {
        return $this->hasOne(ChatNotification::class)->ofMany(['id' => 'max'], fn (Builder $query) => $query->where(
            fn (Builder $query) => $query->whereNotNull('sent_at')->orWhereNotNull('failed_at'),
        ));
    }

    public function googleChatStatus(): GoogleChatStatus
    {
        if (blank($this->google_chat_webhook_url)) {
            return GoogleChatStatus::NotConfigured;
        }

        return $this->latestChatNotification?->failed_at !== null
            ? GoogleChatStatus::Failing
            : GoogleChatStatus::Active;
    }

    /**
     * The Google Chat space the team's webhook posts to, e.g. "AAAA1234". Null without a Google Chat webhook.
     */
    public function googleChatSpace(): ?string
    {
        return self::googleChatSpaceOf($this->google_chat_webhook_url);
    }

    /**
     * The space of a Google Chat webhook URL, e.g. "AAAA1234" for "https://chat.googleapis.com/v1/spaces/AAAA1234/messages?…".
     */
    public static function googleChatSpaceOf(?string $webhookUrl): ?string
    {
        if (! is_string($webhookUrl) || ! str_starts_with($webhookUrl, self::GOOGLE_CHAT_WEBHOOK_PREFIX)) {
            return null;
        }

        $space = Str::of($webhookUrl)->after(self::GOOGLE_CHAT_WEBHOOK_PREFIX)->before('/')->before('?')->toString();

        return $space === '' ? null : $space;
    }

    /**
     * The webhook URL with the space id shortened and the key and token hidden, e.g. ".../spaces/AAAA…/messages?key=••••".
     */
    public function maskedGoogleChatWebhookUrl(): ?string
    {
        if (blank($this->google_chat_webhook_url)) {
            return null;
        }

        $space = Str::of($this->google_chat_webhook_url)
            ->after(self::GOOGLE_CHAT_WEBHOOK_PREFIX)
            ->before('/')
            ->limit(4, '…');

        return self::GOOGLE_CHAT_WEBHOOK_PREFIX.$space.'/messages?key=••••';
    }
}
