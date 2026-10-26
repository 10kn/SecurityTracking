<?php

namespace App\Actions;

use App\Enums\IncidentSuggestionStatus;
use App\Models\Advisory;
use App\Models\Incident;
use App\Models\IncidentSuggestion;
use App\Models\User;
use App\Support\IncidentLock;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * An admin accepts a suggestion: only the suggested pre-notice moves to the suggested incident. A pre-notice alone in
 * its incident merges that incident (see MergeIncidents); one sharing it is split off first (see SplitNotice) and the
 * new incident merged, both in one transaction under the technology's IncidentLock, so no alert run sees, or sends
 * anything about, the incident in between.
 *
 * The suggestion must still be pending, the pre-notice still in the incident the admin saw it in, and the suggestion
 * still point at the incident the admin saw, which must still be current, all checked under the lock; otherwise
 * nothing changes. A suggestion moved to another incident by a merge is not followed there.
 */
class AcceptIncidentSuggestion
{
    public function __construct(private SplitNotice $splitNotice, private MergeIncidents $mergeIncidents) {}

    /**
     * @param  int  $expectedIncidentId  The incident the admin saw the pre-notice in.
     * @param  int  $expectedTargetId  The incident the admin saw the suggestion point at.
     * @return Incident The incident the pre-notice is in now.
     *
     * @throws InvalidArgumentException When the suggestion, the pre-notice or the incidents changed meanwhile.
     */
    public function handle(IncidentSuggestion $suggestion, int $expectedIncidentId, int $expectedTargetId, User $user): Incident
    {
        return DB::transaction(function () use ($suggestion, $expectedIncidentId, $expectedTargetId, $user): Incident {
            $target = Incident::find($suggestion->incident_id);

            if ($target === null) {
                throw new InvalidArgumentException('The suggestion no longer applies.');
            }

            IncidentLock::take([$target->technology]);

            $suggestion = IncidentSuggestion::whereKey($suggestion->id)->lockForUpdate()->first();
            $notice = Advisory::whereKey($suggestion?->advisory_id)->first();
            $incidents = Incident::whereKey(array_filter([$suggestion?->incident_id, $notice?->incident_id]))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $target = $incidents->get($suggestion?->incident_id);
            $source = $incidents->get($notice?->incident_id);

            if ($suggestion === null || $suggestion->status !== IncidentSuggestionStatus::Pending || $suggestion->incident_id !== $expectedTargetId || $notice === null || $notice->incident_id !== $expectedIncidentId
                || $source === null || $target === null || $source->is($target) || $target->merged_into_id !== null || $source->merged_into_id !== null || $source->technology !== $target->technology) {
                throw new InvalidArgumentException('The suggestion, its pre-notice or its incident changed.');
            }

            $from = $source->notices()->count() < 2 ? $source : $this->splitNotice->handle($notice, $user, $expectedIncidentId);
            $into = $this->mergeIncidents->handle($from, $target, $user);

            IncidentSuggestion::whereKey($suggestion->id)
                ->where('status', IncidentSuggestionStatus::Pending)
                ->update(['status' => IncidentSuggestionStatus::Accepted, 'decided_by' => $user->id, 'decided_at' => now()]);

            return $into;
        });
    }
}
