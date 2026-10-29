<?php

namespace App\Models;

use App\Enums\IncidentAlertReason;
use App\Enums\Severity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * A pre-notice a team was told about in a card, at the severity it had then. Recorded in the transaction that queues
 * the card, so a card is never queued twice for the same news.
 */
#[Fillable(['team_id', 'advisory_id', 'severity', 'reason', 'chat_notification_id'])]
class IncidentTeamNotice extends Model
{
    const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'severity' => Severity::class,
            'reason' => IncidentAlertReason::class,
        ];
    }

    /**
     * The highest severity a team was sent any of the pre-notices at, or null when it was sent none. Cards from before
     * incidents (`pre_notice:{advisory}:{team}`, with the severity since it was part of the key, else read from the
     * title "[High] …") count too.
     *
     * @param  list<int>  $advisoryIds
     */
    public static function severityToldTo(int $teamId, array $advisoryIds): ?Severity
    {
        if ($advisoryIds === []) {
            return null;
        }

        $told = self::where('team_id', $teamId)->whereIn('advisory_id', $advisoryIds)->pluck('severity');

        $legacy = self::legacyCards($teamId, $advisoryIds)->get()->map(fn (ChatNotification $notification): Severity => substr_count($notification->dedupe_key, ':') === 3
            ? Severity::tryFrom(Str::afterLast($notification->dedupe_key, ':')) ?? Severity::Unknown
            : self::severityOfTitle((string) ($notification->payload['text'] ?? '')));

        return $told->merge($legacy)->sortByDesc(fn (Severity $severity): int => $severity->rank())->first();
    }

    /**
     * Whether the team was sent any of the pre-notices in a card, including a card from before incidents.
     *
     * @param  list<int>  $advisoryIds
     */
    public static function wasSentTo(int $teamId, array $advisoryIds): bool
    {
        return $advisoryIds !== [] && (
            self::where('team_id', $teamId)->whereIn('advisory_id', $advisoryIds)->whereNotNull('chat_notification_id')->exists()
            || self::legacyCards($teamId, $advisoryIds)->exists()
        );
    }

    /**
     * Whether any team was sent any of the pre-notices in a card, including a card from before incidents.
     *
     * @param  list<int>  $advisoryIds
     */
    public static function wasSentToAnyTeam(array $advisoryIds): bool
    {
        return $advisoryIds !== [] && (
            self::whereIn('advisory_id', $advisoryIds)->whereNotNull('chat_notification_id')->exists()
            || ChatNotification::where(function ($query) use ($advisoryIds): void {
                foreach ($advisoryIds as $advisoryId) {
                    $query->orWhere('dedupe_key', 'like', "pre_notice:{$advisoryId}:%");
                }
            })->whereNotLike('dedupe_key', 'pre_notice:incident:%')->exists()
        );
    }

    /**
     * What every team was told of the pre-notices, read in two queries for many incidents at once: one entry per
     * pre-notice, team and severity, `is_sent` when it was in a card. Cards from before incidents count as sent, with
     * their severity read as severityToldTo() does, and are `is_legacy`: they belong to no incident thread.
     *
     * @param  list<int>  $advisoryIds
     * @return Collection<int, array{team_id: int, advisory_id: int, severity: Severity, is_sent: bool, is_legacy: bool}>
     */
    public static function ledgerFor(array $advisoryIds): Collection
    {
        if ($advisoryIds === []) {
            return collect();
        }

        $told = self::whereIn('advisory_id', $advisoryIds)->get(['team_id', 'advisory_id', 'severity', 'chat_notification_id'])->map(fn (self $notice): array => [
            'team_id' => (int) $notice->team_id,
            'advisory_id' => (int) $notice->advisory_id,
            'severity' => $notice->severity,
            'is_sent' => $notice->chat_notification_id !== null,
            'is_legacy' => false,
        ]);

        $legacy = ChatNotification::where(function ($query) use ($advisoryIds): void {
            foreach ($advisoryIds as $advisoryId) {
                $query->orWhere('dedupe_key', 'like', "pre_notice:{$advisoryId}:%");
            }
        })
            ->whereNotLike('dedupe_key', 'pre_notice:incident:%')
            ->get(['team_id', 'dedupe_key', 'payload'])
            ->map(fn (ChatNotification $notification): array => [
                'team_id' => (int) $notification->team_id,
                'advisory_id' => (int) Str::betweenFirst($notification->dedupe_key, 'pre_notice:', ':'),
                'severity' => substr_count($notification->dedupe_key, ':') === 3
                    ? Severity::tryFrom(Str::afterLast($notification->dedupe_key, ':')) ?? Severity::Unknown
                    : self::severityOfTitle((string) ($notification->payload['text'] ?? '')),
                'is_sent' => true,
                'is_legacy' => true,
            ]);

        return $told->merge($legacy)->values();
    }

    /**
     * The teams sent any of the pre-notices in a card, including a card from before incidents.
     *
     * @param  list<int>  $advisoryIds
     * @return Collection<int, int>
     */
    public static function teamsSentTo(array $advisoryIds): Collection
    {
        if ($advisoryIds === []) {
            return collect();
        }

        $legacy = ChatNotification::where(function ($query) use ($advisoryIds): void {
            foreach ($advisoryIds as $advisoryId) {
                $query->orWhere('dedupe_key', 'like', "pre_notice:{$advisoryId}:%");
            }
        })
            ->whereNotLike('dedupe_key', 'pre_notice:incident:%')
            ->pluck('team_id');

        return self::whereIn('advisory_id', $advisoryIds)->whereNotNull('chat_notification_id')->distinct()->pluck('team_id')
            ->merge($legacy)
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->sort()
            ->values();
    }

    /**
     * The pre-notices the team was sent in a card, including cards from before incidents.
     *
     * @return Collection<int, int>
     */
    public static function noticeIdsSentTo(int $teamId): Collection
    {
        $legacy = ChatNotification::where('team_id', $teamId)
            ->whereLike('dedupe_key', 'pre_notice:%')
            ->whereNotLike('dedupe_key', 'pre_notice:incident:%')
            ->pluck('dedupe_key')
            ->map(fn (string $key): int => (int) Str::betweenFirst($key, 'pre_notice:', ':'));

        return self::where('team_id', $teamId)->whereNotNull('chat_notification_id')->distinct()->pluck('advisory_id')->merge($legacy)->unique()->values();
    }

    /**
     * The pre-notices any team was sent in a card, including cards from before incidents.
     *
     * @return Collection<int, int>
     */
    public static function toldNoticeIds(): Collection
    {
        $legacy = ChatNotification::whereLike('dedupe_key', 'pre_notice:%')
            ->whereNotLike('dedupe_key', 'pre_notice:incident:%')
            ->pluck('dedupe_key')
            ->map(fn (string $key): int => (int) Str::betweenFirst($key, 'pre_notice:', ':'));

        return self::whereNotNull('chat_notification_id')->distinct()->pluck('advisory_id')->merge($legacy)->unique()->values();
    }

    /**
     * When a team was first sent any of the pre-notices, or null when it was sent none. A card from before incidents
     * counts from when it was queued.
     *
     * @param  list<int>  $advisoryIds
     */
    public static function firstToldAt(int $teamId, array $advisoryIds): ?Carbon
    {
        if ($advisoryIds === []) {
            return null;
        }

        return collect([
            self::where('team_id', $teamId)->whereIn('advisory_id', $advisoryIds)->min('created_at'),
            self::legacyCards($teamId, $advisoryIds)->min('created_at'),
        ])->filter()->map(fn (string $time): Carbon => Carbon::parse($time))->min();
    }

    /**
     * Cards from before incidents, one per pre-notice and team: `pre_notice:{advisory}:{team}`, followed by the
     * severity once it was part of the key.
     *
     * @param  non-empty-list<int>  $advisoryIds
     * @return Builder<ChatNotification>
     */
    private static function legacyCards(int $teamId, array $advisoryIds): Builder
    {
        return ChatNotification::where(function ($query) use ($advisoryIds, $teamId): void {
            foreach ($advisoryIds as $advisoryId) {
                $query->orWhere('dedupe_key', "pre_notice:{$advisoryId}:{$teamId}")->orWhere('dedupe_key', 'like', "pre_notice:{$advisoryId}:{$teamId}:%");
            }
        });
    }

    private static function severityOfTitle(string $title): Severity
    {
        foreach ([Severity::Critical, Severity::High, Severity::Medium, Severity::Low] as $severity) {
            if (str_starts_with($title, "[{$severity->getLabel()}]")) {
                return $severity;
            }
        }

        return Severity::Unknown;
    }

    /**
     * @return BelongsTo<Advisory, $this>
     */
    public function notice(): BelongsTo
    {
        return $this->belongsTo(Advisory::class, 'advisory_id');
    }

    /**
     * @return BelongsTo<ChatNotification, $this>
     */
    public function chatNotification(): BelongsTo
    {
        return $this->belongsTo(ChatNotification::class);
    }
}
