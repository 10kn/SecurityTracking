<?php

namespace App\Models;

use App\Enums\IncidentSuggestionReason;
use App\Enums\IncidentSuggestionStatus;
use Database\Factories\IncidentSuggestionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A hint that a pre-notice may belong to another incident, for an admin to merge or reject. A rejected suggestion
 * stays rejected; another reason for the same pair is a new suggestion.
 */
#[Fillable(['advisory_id', 'incident_id', 'reason', 'evidence', 'status', 'decided_by', 'decided_at'])]
class IncidentSuggestion extends Model
{
    /** @use HasFactory<IncidentSuggestionFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reason' => IncidentSuggestionReason::class,
            'evidence' => 'array',
            'status' => IncidentSuggestionStatus::class,
            'decided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Advisory, $this>
     */
    public function notice(): BelongsTo
    {
        return $this->belongsTo(Advisory::class, 'advisory_id');
    }

    /**
     * @return BelongsTo<Incident, $this>
     */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
