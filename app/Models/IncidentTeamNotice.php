<?php

namespace App\Models;

use App\Enums\IncidentAlertReason;
use App\Enums\Severity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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

        $legacy = ChatNotification::where(function ($query) use ($advisoryIds, $teamId): void {
            foreach ($advisoryIds as $advisoryId) {
                $query->orWhere('dedupe_key', "pre_notice:{$advisoryId}:{$teamId}")->orWhere('dedupe_key', 'like', "pre_notice:{$advisoryId}:{$teamId}:%");
            }
        })->get()->map(fn (ChatNotification $notification): Severity => substr_count($notification->dedupe_key, ':') === 3
            ? Severity::tryFrom(Str::afterLast($notification->dedupe_key, ':')) ?? Severity::Unknown
            : self::severityOfTitle((string) ($notification->payload['text'] ?? '')));

        return $told->merge($legacy)->sortByDesc(fn (Severity $severity): int => $severity->rank())->first();
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
