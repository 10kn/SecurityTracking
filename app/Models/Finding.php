<?php

namespace App\Models;

use App\Enums\FindingRule;
use App\Enums\FindingState;
use App\Enums\Severity;
use Database\Factories\FindingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['fingerprint', 'team_id', 'deployment_id', 'advisory_id', 'runtime_id', 'component_id', 'rule', 'severity', 'state', 'title', 'group_key', 'context', 'first_seen_at', 'last_seen_at', 'sla_due_at', 'resolved_at', 'accepted_until', 'notified_severity', 'notified_at', 'acknowledged_at', 'acknowledged_by'])]
class Finding extends Model
{
    /** @use HasFactory<FindingFactory> */
    use HasFactory;

    /**
     * The CISA KEV evidence of the context lists at least one entry; false when it is missing, null or empty.
     */
    private const string KNOWN_EXPLOITED_SQL = "coalesce(findings.context->'known_exploited' @> '[{}]', false)";

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rule' => FindingRule::class,
            'severity' => Severity::class,
            'state' => FindingState::class,
            'context' => 'array',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'sla_due_at' => 'datetime',
            'resolved_at' => 'datetime',
            'accepted_until' => 'datetime',
            'notified_severity' => Severity::class,
            'notified_at' => 'datetime',
            'acknowledged_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<Finding>  $query
     * @return Builder<Finding>
     */
    #[Scope]
    protected function open(Builder $query): Builder
    {
        return $query->where('state', FindingState::Open);
    }

    /**
     * The findings an admin should start with (the Overview, and `priority=1` of the findings list): CISA KEV lists a
     * CVE of them, their deadline passed, or they are Critical or High and nobody marked them as read.
     *
     * @param  Builder<Finding>  $query
     * @return Builder<Finding>
     */
    #[Scope]
    protected function priority(Builder $query): Builder
    {
        return $query->where(fn (Builder $query) => $query
            ->whereRaw(self::KNOWN_EXPLOITED_SQL)
            ->orWhere('sla_due_at', '<', now())
            ->orWhere(fn (Builder $query) => $query->whereNull('acknowledged_at')->whereIn('severity', [Severity::Critical, Severity::High])));
    }

    /**
     * Priority findings in the order to handle them: CISA KEV, then overdue, then unread Critical, then unread High;
     * the nearest deadline first within each.
     *
     * @param  Builder<Finding>  $query
     * @return Builder<Finding>
     */
    #[Scope]
    protected function orderByPriority(Builder $query): Builder
    {
        return $query
            ->orderByRaw('case when '.self::KNOWN_EXPLOITED_SQL.' then 0 when sla_due_at < ? then 1 when severity = ? then 2 else 3 end', [now(), Severity::Critical->value])
            ->orderBy('sla_due_at')
            ->orderBy('id');
    }

    /**
     * Whether CISA KEV lists a CVE of the finding (see KnownExploitation).
     */
    public function isKnownExploited(): bool
    {
        return filled($this->context['known_exploited'] ?? null);
    }

    /**
     * The title in the current locale. The stored one is written in the alert locale for Google Chat; it is kept
     * when the rule cannot rebuild it from the context.
     */
    public function localizedTitle(): string
    {
        return $this->rule->title($this->context ?? []) ?? $this->title;
    }

    /**
     * The lockfiles holding the affected package versions, relative to the service directory, e.g.
     * ["frontend/package-lock.json"]. Findings opened before lockfiles were searched in subdirectories have one.
     *
     * @return list<string>
     */
    public function lockfilePaths(): array
    {
        $context = $this->context ?? [];
        $paths = $context['lockfiles'] ?? (isset($context['lockfile']) ? [$context['lockfile']] : []);

        return array_values(array_filter($paths, is_string(...)));
    }

    /**
     * Whether an admin marked the finding as read since it opened or last rose in severity.
     */
    public function isAcknowledged(): bool
    {
        return $this->acknowledged_at !== null;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return BelongsTo<Deployment, $this>
     */
    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class);
    }

    /**
     * @return BelongsTo<Advisory, $this>
     */
    public function advisory(): BelongsTo
    {
        return $this->belongsTo(Advisory::class);
    }

    /**
     * @return BelongsTo<Runtime, $this>
     */
    public function runtime(): BelongsTo
    {
        return $this->belongsTo(Runtime::class);
    }

    /**
     * @return BelongsTo<Component, $this>
     */
    public function component(): BelongsTo
    {
        return $this->belongsTo(Component::class);
    }

    /**
     * @return HasMany<FindingEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(FindingEvent::class);
    }
}
