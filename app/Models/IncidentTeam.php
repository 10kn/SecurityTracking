<?php

namespace App\Models;

use App\Enums\ChatThreadRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The Google Chat thread of an incident at one team, and what the team was told about the incident beyond the
 * severities of its pre-notices (see IncidentTeamNotice): the branches the vendor had confirmed as affected and
 * whether it was told the incident is exploited. What the team was told is written when the message is queued,
 * like the other ledgers; the thread's name only once Google Chat delivered a message of it.
 *
 * Each generation is one thread with its own `thread_key`. A team whose webhook changed starts the next generation
 * (see moveTo()), as Google Chat scopes thread keys to the webhook's app: its next alert opens a new thread with a full
 * card. An incident merged into another hands its messages to the row it points at (see canonical()).
 */
#[Table('incident_team')]
#[Fillable(['incident_id', 'team_id', 'generation', 'thread_key', 'space', 'webhook_hash', 'thread_name', 'opening_notification_id', 'last_notification_id', 'merged_into_id', 'told_confirmed_branches', 'exploited_told_at'])]
class IncidentTeam extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'generation' => 'integer',
            'told_confirmed_branches' => 'array',
            'exploited_told_at' => 'datetime',
        ];
    }

    /**
     * The row of the incident and team, created when there is none, locked until the transaction ends. Callers hold
     * the incident's IncidentLock and row lock first.
     *
     * @return array{0: self, 1: bool} The row, and whether it was just created.
     */
    public static function lockFor(Incident $incident, Team $team): array
    {
        $isNew = self::query()->insertOrIgnore([
            'incident_id' => $incident->id,
            'team_id' => $team->id,
            'generation' => 1,
            'thread_key' => self::threadKeyFor($incident->id, $team->id, 1),
            'told_confirmed_branches' => '[]',
            'created_at' => now(),
            'updated_at' => now(),
        ]) === 1;

        return [self::where('incident_id', $incident->id)->where('team_id', $team->id)->lockForUpdate()->firstOrFail(), $isNew];
    }

    /**
     * A thread key no other installation posting to the same space uses: a namespace derived from the application key,
     * the incident, the team and the generation.
     */
    public static function threadKeyFor(int $incidentId, int $teamId, int $generation): string
    {
        $namespace = substr(hash('sha256', 'psm-thread:'.config('app.key')), 0, 12);

        return "psm-{$namespace}-incident-{$incidentId}-team-{$teamId}-g{$generation}";
    }

    /**
     * Follows the team to the webhook it posts to now. The first webhook is only recorded; any other one, in the same
     * space or another, starts the next generation, with no thread yet.
     */
    public function moveTo(?string $webhookUrl): void
    {
        $space = Team::googleChatSpaceOf($webhookUrl);

        if ($space === null) {
            return;
        }

        $hash = hash('sha256', (string) $webhookUrl);

        if ($this->webhook_hash === $hash) {
            return;
        }

        if ($this->webhook_hash !== null || $this->space !== null) {
            $this->generation++;
            $this->thread_key = self::threadKeyFor($this->incident_id, $this->team_id, $this->generation);
            $this->thread_name = null;
            $this->opening_notification_id = null;
        }

        $this->space = $space;
        $this->webhook_hash = $hash;
        $this->save();
    }

    /**
     * The row messages of this one go to now: the row of the incident it was merged into, followed to the end, locked
     * until the transaction ends.
     */
    public function canonical(): self
    {
        $row = $this;

        for ($hops = 0; $row->merged_into_id !== null && $hops < 10; $hops++) {
            $next = self::whereKey($row->merged_into_id)->lockForUpdate()->first();

            if ($next === null) {
                break;
            }

            $row = $next;
        }

        return $row;
    }

    /**
     * What the next message of the thread is queued as: a reply while the thread's opener was delivered with its
     * thread or is still waiting to be sent, else the opener. An opener that failed or came back without a thread
     * leaves the thread to be opened again.
     */
    public function nextRole(): ChatThreadRole
    {
        $opener = $this->opening_notification_id === null ? null : ChatNotification::find($this->opening_notification_id);

        return match (true) {
            $opener === null, $opener->failed_at !== null => ChatThreadRole::Opener,
            $opener->sent_at !== null && $this->thread_name === null => ChatThreadRole::Opener,
            default => ChatThreadRole::Reply,
        };
    }

    /**
     * Records the message queued for the thread; one queued as the opener opens it.
     */
    public function recordQueued(ChatNotification $notification): void
    {
        $this->last_notification_id = $notification->id;

        if ($notification->thread_role === ChatThreadRole::Opener) {
            $this->opening_notification_id = $notification->id;
        }

        $this->save();
    }

    /**
     * The thread columns a message of this row is queued with.
     *
     * @return array{incident_team_id: int, thread_key: string, generation: int, thread_role: ChatThreadRole}
     */
    public function threadAttributes(ChatThreadRole $role): array
    {
        return ['incident_team_id' => $this->id, 'thread_key' => $this->thread_key, 'generation' => $this->generation, 'thread_role' => $role];
    }

    /**
     * Takes over the thread of another row, when this one has none of its own (see MergeIncidents).
     */
    public function takeOverThreadOf(self $other): void
    {
        $this->fill([
            'generation' => $other->generation,
            'thread_key' => $other->thread_key,
            'space' => $other->space,
            'webhook_hash' => $other->webhook_hash,
            'thread_name' => $other->thread_name,
            'opening_notification_id' => $other->opening_notification_id,
        ]);
    }

    /**
     * Whether a message was ever queued for the thread of this generation.
     */
    public function hasThread(): bool
    {
        return $this->opening_notification_id !== null || $this->thread_name !== null;
    }

    /**
     * @return BelongsTo<Incident, $this>
     */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return BelongsTo<ChatNotification, $this>
     */
    public function openingNotification(): BelongsTo
    {
        return $this->belongsTo(ChatNotification::class, 'opening_notification_id');
    }

    /**
     * @return BelongsTo<IncidentTeam, $this>
     */
    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_id');
    }
}
