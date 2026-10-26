<?php

namespace App\Actions;

use App\Enums\IncidentBasis;
use App\Enums\IncidentSuggestionStatus;
use App\Models\Advisory;
use App\Models\Incident;
use App\Models\IncidentSuggestion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * An admin merges an incident into another of the same technology: its pre-notices move over and count as placed by
 * the admin, and it keeps its row with `merged_into_id`. What each team was told is read from the pre-notices, so the
 * merged incident does not tell a team again what it already heard from either.
 *
 * Pending suggestions between the two are accepted; the others pointing at the merged incident now point at the one
 * it was merged into.
 */
class MergeIncidents
{
    public function __construct(private AssignNoticeToIncident $assignNoticeToIncident) {}

    /**
     * @throws InvalidArgumentException When the incidents cannot be merged.
     */
    public function handle(Incident $from, Incident $into, User $user): Incident
    {
        return DB::transaction(function () use ($from, $into, $user): Incident {
            $this->assignNoticeToIncident->lock($from->technology);

            $incidents = Incident::whereKey([$from->id, $into->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $from = $incidents->get($from->id);
            $into = $incidents->get($into->id);

            if ($from === null || $into === null || $from->is($into) || $from->technology !== $into->technology || $from->merged_into_id !== null || $into->merged_into_id !== null) {
                throw new InvalidArgumentException('Only two current incidents of the same technology can be merged.');
            }

            $moved = $from->notices()->get();

            $moved->each(fn (Advisory $notice) => $notice->update([
                'incident_id' => $into->id,
                'incident_basis' => IncidentBasis::Manual,
                'incident_evidence' => [['type' => 'merge', 'from_incident_id' => $from->id, 'user_id' => $user->id]],
            ]));

            $from->update(['merged_into_id' => $into->id]);
            Incident::where('merged_into_id', $from->id)->update(['merged_into_id' => $into->id]);

            $this->moveSuggestions($from, $into, $user);
            $from->refreshFromNotices();

            return $into->refresh();
        });
    }

    private function moveSuggestions(Incident $from, Incident $into, User $user): void
    {
        IncidentSuggestion::where('incident_id', $from->id)->where('status', IncidentSuggestionStatus::Pending)->get()->each(function (IncidentSuggestion $suggestion) use ($into): void {
            $exists = IncidentSuggestion::where(['advisory_id' => $suggestion->advisory_id, 'incident_id' => $into->id, 'reason' => $suggestion->reason])->exists();

            $exists ? $suggestion->delete() : $suggestion->update(['incident_id' => $into->id]);
        });

        IncidentSuggestion::where('incident_id', $into->id)
            ->where('status', IncidentSuggestionStatus::Pending)
            ->whereIn('advisory_id', $into->notices()->select('id'))
            ->update(['status' => IncidentSuggestionStatus::Accepted, 'decided_by' => $user->id, 'decided_at' => now()]);
    }
}
