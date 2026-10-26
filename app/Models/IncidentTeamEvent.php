<?php

namespace App\Models;

use App\Enums\IncidentTeamEventType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The history of an incident at one team, only ever added to: an admin's assessment ("Đã đọc" with its impact), the
 * reset of an assessment that stopped holding once the team's exposure grew, and an urgent update an admin sent.
 */
#[Fillable(['incident_team_id', 'type', 'data', 'user_id'])]
class IncidentTeamEvent extends Model
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
            'type' => IncidentTeamEventType::class,
            'data' => 'array',
        ];
    }

    /**
     * @return BelongsTo<IncidentTeam, $this>
     */
    public function incidentTeam(): BelongsTo
    {
        return $this->belongsTo(IncidentTeam::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
