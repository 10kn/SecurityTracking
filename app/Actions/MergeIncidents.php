<?php

namespace App\Actions;

use App\Enums\IncidentBasis;
use App\Enums\IncidentSuggestionStatus;
use App\Models\Advisory;
use App\Models\Incident;
use App\Models\IncidentSuggestion;
use App\Models\IncidentTeam;
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
 *
 * Each team's Google Chat thread of the merged incident stays on its IncidentTeam row, which points at the row of the
 * incident it went into (see mergeThreads()). An admin's assessment of an incident for a team ("Đã đọc") stays on its
 * own row: the incident it went into keeps its own, which holds only while it still covers what the merged pre-notices
 * ask of the team (see IncidentTeamExposure::isCoveredBy()); a team that only assessed the merged one is unassessed.
 * It may run inside a caller's transaction (see AcceptIncidentSuggestion).
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
            $this->mergeThreads($from, $into);
            $from->refreshFromNotices();

            return $into->refresh();
        });
    }

    /**
     * A team with a thread only on the merged incident keeps it: the row of the incident it went into takes it over,
     * so updates go on in the thread the team knows. A team with a thread on both keeps the one of the incident it
     * went into. What the team was told about either incident (confirmed branches, exploitation, the CVE ids CISA KEV
     * lists) counts for both. The rows are locked after the incidents, as SendPreNoticeAlerts locks them.
     */
    private function mergeThreads(Incident $from, Incident $into): void
    {
        $targets = IncidentTeam::where('incident_id', $into->id)->orderBy('id')->lockForUpdate()->get()->keyBy('team_id');

        IncidentTeam::where('incident_id', $from->id)->orderBy('id')->lockForUpdate()->get()->each(function (IncidentTeam $row) use ($into, $targets): void {
            $target = $targets->get($row->team_id) ?? new IncidentTeam([
                'incident_id' => $into->id,
                'team_id' => $row->team_id,
                'generation' => 1,
                'thread_key' => IncidentTeam::threadKeyFor($into->id, $row->team_id, 1),
            ]);

            if (! $target->hasThread() && $row->hasThread()) {
                $target->takeOverThreadOf($row);
            }

            $target->told_confirmed_branches = collect([...($target->told_confirmed_branches ?? []), ...($row->told_confirmed_branches ?? [])])->unique()->sort(SORT_NATURAL)->values()->all();
            $target->exploited_told_at = collect([$target->exploited_told_at, $row->exploited_told_at])->filter()->min();
            $target->told_known_exploited = collect([...($target->told_known_exploited ?? []), ...($row->told_known_exploited ?? [])])->unique()->sort()->values()->all() ?: null;
            $target->last_notification_id ??= $row->last_notification_id;
            $target->save();

            IncidentTeam::where('merged_into_id', $row->id)->update(['merged_into_id' => $target->id]);
            $row->update(['merged_into_id' => $target->id]);
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
