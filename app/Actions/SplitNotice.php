<?php

namespace App\Actions;

use App\Enums\IncidentBasis;
use App\Models\Advisory;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * An admin takes a pre-notice out of its incident into a new one. It counts as placed by the admin, so no rule puts it
 * back. A team that never had the pre-notice in a card gets the new incident as news. The new incident starts with no
 * Google Chat thread: a team told about the pre-notice hears about its next update in a new thread, with a full card.
 */
class SplitNotice
{
    public function __construct(private AssignNoticeToIncident $assignNoticeToIncident) {}

    /**
     * @throws InvalidArgumentException When the pre-notice is alone in its incident.
     */
    public function handle(Advisory $notice, User $user): Incident
    {
        return DB::transaction(function () use ($notice, $user): Incident {
            $this->assignNoticeToIncident->lock($notice->technology);
            $notice->refresh();

            $from = Incident::whereKey($notice->incident_id)->lockForUpdate()->first();

            if ($from === null || $from->notices()->count() < 2) {
                throw new InvalidArgumentException('Only a pre-notice that shares its incident can be split off.');
            }

            $incident = Incident::create([
                'technology' => $notice->technology,
                'title' => $notice->title,
                'severity' => $notice->severity,
                'first_published_at' => $notice->published_at,
                'changed_at' => now(),
            ]);

            $notice->update([
                'incident_id' => $incident->id,
                'incident_basis' => IncidentBasis::Manual,
                'incident_evidence' => [['type' => 'split', 'from_incident_id' => $from->id, 'user_id' => $user->id]],
            ]);

            return $incident->refresh();
        });
    }
}
