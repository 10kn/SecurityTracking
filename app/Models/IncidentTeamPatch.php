<?php

namespace App\Models;

use App\Enums\Severity;
use App\Enums\Technology;
use App\Support\Version;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A "Đã có bản vá" card a team got: the vendor confirmed `version` fixes the incident on `branch`, and the catalog
 * lists it as a security release. Recorded in the transaction that queues the card, with the projects whose services
 * it listed on the branch (`project_ids`; null for the cards from before, which stand for every project of the team).
 */
#[Fillable(['team_id', 'advisory_id', 'technology', 'branch', 'version', 'severity', 'project_ids', 'chat_notification_id'])]
class IncidentTeamPatch extends Model
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
            'technology' => Technology::class,
            'severity' => Severity::class,
            'project_ids' => 'array',
        ];
    }

    /**
     * The most severe card that told the team to upgrade the branch to `$target` or a later version, listing the
     * services of `$projectId` on it, so a finding of the project asking for `$target` is covered by it. A finding
     * asking for a version newer than every card is not: the vendor has not said that release fixes the incident.
     * Null when there is none.
     */
    public static function covering(int $teamId, int $projectId, Technology $technology, string $branch, string $target): ?self
    {
        return self::where('team_id', $teamId)
            ->where('technology', $technology)
            ->where('branch', $branch)
            ->get()
            ->filter(fn (self $patch): bool => $patch->covers($projectId) && ! Version::isNewer($target, $patch->version))
            ->sortByDesc(fn (self $patch): int => $patch->severity->rank())
            ->first();
    }

    /**
     * Whether the card listed the services of the project on its branch.
     */
    public function covers(int $projectId): bool
    {
        return $this->project_ids === null || in_array($projectId, $this->project_ids, true);
    }

    /**
     * @return BelongsTo<Advisory, $this>
     */
    public function notice(): BelongsTo
    {
        return $this->belongsTo(Advisory::class, 'advisory_id');
    }
}
