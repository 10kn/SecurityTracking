<?php

namespace App\Models;

use App\Enums\GoogleChatStatus;
use App\Enums\NotificationTarget;
use App\Enums\Technology;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;

/**
 * A project of a team. An admin can pause its monitoring ("Ngừng theo dõi") once it is no longer maintained: its
 * services are still scanned, but nothing tells the team about them until it is resumed (see PauseProjectMonitoring
 * and ResumeProjectMonitoring).
 *
 * Its Google Chat messages go to its team's space, or to a webhook of its own (`notification_target`, see
 * ChatRecipient): a project on its own webhook is then left out of every message to its team.
 */
#[Fillable(['team_id', 'name', 'slug', 'notification_target', 'google_chat_webhook_url', 'monitoring_paused_at', 'monitoring_paused_by', 'monitoring_pause_reason'])]
#[Hidden(['google_chat_webhook_url'])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'notification_target' => 'team',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'monitoring_paused_at' => 'datetime',
            'notification_target' => NotificationTarget::class,
            'google_chat_webhook_url' => 'encrypted',
        ];
    }

    /**
     * Whether the project's messages go to its own webhook instead of its team's.
     */
    public function ownsChatChannel(): bool
    {
        return $this->notification_target === NotificationTarget::Project;
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeOwningChatChannel(Builder $query): void
    {
        $query->where('notification_target', NotificationTarget::Project);
    }

    /**
     * The status of the project's own webhook, read like a team's (see Team::googleChatStatus()).
     */
    public function googleChatStatus(): GoogleChatStatus
    {
        if (blank($this->google_chat_webhook_url)) {
            return GoogleChatStatus::NotConfigured;
        }

        return $this->latestChatNotification?->failed_at !== null
            ? GoogleChatStatus::Failing
            : GoogleChatStatus::Active;
    }

    public function maskedGoogleChatWebhookUrl(): ?string
    {
        return Team::maskGoogleChatWebhookUrl($this->google_chat_webhook_url);
    }

    public function isPaused(): bool
    {
        return $this->monitoring_paused_at !== null;
    }

    /**
     * The technologies its services run on current deployments, whose IncidentLock pausing and resuming it take.
     *
     * @return Collection<int, Technology>
     */
    public function currentTechnologies(): Collection
    {
        return Runtime::whereHas('deployment', fn ($query) => $query->where('is_current', true)->whereHas('service', fn ($query) => $query->where('project_id', $this->id)))
            ->distinct()
            ->pluck('technology')
            ->values();
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return HasMany<ChatNotification, $this>
     */
    public function chatNotifications(): HasMany
    {
        return $this->hasMany(ChatNotification::class);
    }

    /**
     * The latest message to the project's own webhook that was delivered or gave up (see Team::latestChatNotification()).
     *
     * @return HasOne<ChatNotification, $this>
     */
    public function latestChatNotification(): HasOne
    {
        return $this->hasOne(ChatNotification::class)->ofMany(['id' => 'max'], fn (Builder $query) => $query->where(
            fn (Builder $query) => $query->whereNotNull('sent_at')->orWhereNotNull('failed_at'),
        ));
    }

    /**
     * @return HasMany<Service, $this>
     */
    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function pausedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'monitoring_paused_by');
    }

    /**
     * @return HasMany<ProjectEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(ProjectEvent::class);
    }
}
