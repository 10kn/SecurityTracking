<?php

namespace App\Models;

use App\Enums\ChatThreadRole;
use App\Enums\IncidentImpact;
use App\Enums\IncidentTeamEventType;
use App\Services\IncidentTeamExposure;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The Google Chat thread of an incident at one team, and what the team was told about the incident beyond the
 * severities of its pre-notices (see IncidentTeamNotice): the branches the vendor had confirmed as affected,
 * whether it was told the incident is exploited and the CVE ids CISA KEV lists it was told about. What the team was
 * told is written when the message is queued, like the other ledgers; the thread's name only once Google Chat
 * delivered a message of it.
 *
 * Each generation is one thread with its own `thread_key`. A team whose webhook changed starts the next generation
 * (see moveTo()), as Google Chat scopes thread keys to the webhook's app: its next alert opens a new thread with a full
 * card. An incident merged into another hands its messages to the row it points at (see canonical()).
 *
 * It also holds an admin's assessment of the incident for the team ("Đã đọc", see AcknowledgeIncidentTeams),
 * independent of the thread: the impact, a note and the exposure the admin saw. The assessment holds only while that
 * exposure still covers the team's current one (see hasValidAssessment()); its history is in IncidentTeamEvent.
 */
#[Table('incident_team')]
#[Fillable(['incident_id', 'team_id', 'generation', 'thread_key', 'space', 'webhook_hash', 'thread_name', 'opening_notification_id', 'last_notification_id', 'merged_into_id', 'told_confirmed_branches', 'exploited_told_at', 'told_known_exploited', 'acknowledged_at', 'acknowledged_by', 'impact', 'impact_note', 'acknowledged_exposure', 'acknowledged_exposure_hash', 'assessment_revision'])]
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
            'told_known_exploited' => 'array',
            'acknowledged_at' => 'datetime',
            'impact' => IncidentImpact::class,
            'acknowledged_exposure' => 'array',
            'assessment_revision' => 'integer',
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
     * Whether the team's next message starts the next generation (see moveTo()): it posts to another webhook than the
     * one this thread was opened with. Read only; the row moves when that message is queued.
     */
    public function willMoveTo(?string $webhookUrl): bool
    {
        return Team::googleChatSpaceOf($webhookUrl) !== null
            && $this->webhook_hash !== hash('sha256', (string) $webhookUrl)
            && ($this->webhook_hash !== null || $this->space !== null);
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
     * Whether an admin assessed the incident for the team and the assessment still holds: the exposure the admin saw
     * covers the current one (see IncidentTeamExposure::isCoveredBy()).
     */
    public function hasValidAssessment(IncidentTeamExposure $exposure): bool
    {
        return $this->impact !== null && $exposure->isCoveredBy($this->acknowledged_exposure);
    }

    /**
     * Whether an admin said the incident does not affect the team ("Không") and that still holds: the team hears no
     * more of it until its exposure grows.
     */
    public function isRuledOut(IncidentTeamExposure $exposure): bool
    {
        return $this->impact === IncidentImpact::No && $this->hasValidAssessment($exposure);
    }

    /**
     * Whether an admin said the incident does not affect the team and that still holds, read from the database.
     */
    public static function isRuledOutFor(Incident $incident, int $teamId): bool
    {
        $row = self::where('incident_id', $incident->id)->where('team_id', $teamId)->first();

        return $row?->impact === IncidentImpact::No && $row->isRuledOut(IncidentTeamExposure::for($incident, $teamId));
    }

    /**
     * Records the admin's assessment of the exposure shown, replacing any earlier one; the history keeps both, each with
     * the full exposure it was made on.
     */
    public function acknowledge(User $user, IncidentImpact $impact, ?string $note, IncidentTeamExposure $exposure): void
    {
        $previous = $this->impact;

        $this->fill([
            'acknowledged_at' => now(),
            'acknowledged_by' => $user->id,
            'impact' => $impact,
            'impact_note' => $note,
            'acknowledged_exposure' => $exposure->toArray(),
            'acknowledged_exposure_hash' => $exposure->hash(),
            'assessment_revision' => $this->assessment_revision + 1,
        ])->save();

        $this->events()->create([
            'type' => IncidentTeamEventType::Acknowledged,
            'user_id' => $user->id,
            'data' => ['impact' => $impact->value, 'note' => $note, 'previous_impact' => $previous?->value, 'exposure_hash' => $exposure->hash(), 'exposure' => $exposure->toArray(), 'user' => $user->name],
        ]);
    }

    /**
     * Clears an assessment that no longer holds for the exposure and records why, once, with the exposure it was made
     * on and the one that ended it: a row with no assessment is left alone. Callers hold the row locked.
     *
     * @return bool Whether it was reset.
     */
    public function resetStaleAssessment(IncidentTeamExposure $exposure): bool
    {
        if ($this->impact === null || $this->hasValidAssessment($exposure)) {
            return false;
        }

        $this->clearAssessment($exposure);

        return true;
    }

    /**
     * Clears the assessment, whether or not it still holds, because the admin resumed a project of the team whose
     * services it was made on: they were not maintained meanwhile, so it has to be made again. Callers hold the row
     * locked.
     *
     * @return bool Whether there was one to reset.
     */
    public function resetForResumedProject(Project $project, User $user, IncidentTeamExposure $exposure): bool
    {
        if ($this->impact === null) {
            return false;
        }

        $this->clearAssessment($exposure, ['project_id' => $project->id, 'project' => $project->name, 'user' => $user->name], $user);

        return true;
    }

    /**
     * @param  array<string, mixed>  $why  What ended it, besides the exposure.
     */
    private function clearAssessment(IncidentTeamExposure $exposure, array $why = [], ?User $user = null): void
    {
        $this->events()->create([
            'type' => IncidentTeamEventType::ImpactReset,
            'user_id' => $user?->id,
            'data' => [
                'impact' => $this->impact->value,
                'note' => $this->impact_note,
                'acknowledged_exposure_hash' => $this->acknowledged_exposure_hash,
                'acknowledged_exposure' => $this->acknowledged_exposure,
                'exposure_hash' => $exposure->hash(),
                'exposure' => $exposure->toArray(),
                ...$why,
            ],
        ]);

        $this->fill([
            'acknowledged_at' => null,
            'acknowledged_by' => null,
            'impact' => null,
            'impact_note' => null,
            'acknowledged_exposure' => null,
            'acknowledged_exposure_hash' => null,
            'assessment_revision' => $this->assessment_revision + 1,
        ])->save();
    }

    /**
     * @return HasMany<IncidentTeamEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(IncidentTeamEvent::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
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
