<?php

namespace App\Actions;

use App\Enums\IncidentImpact;
use App\Models\Incident;
use App\Models\IncidentTeam;
use App\Models\IncidentTeamNotice;
use App\Models\Team;
use App\Models\User;
use App\Services\IncidentTeamExposure;
use App\Support\IncidentLock;
use Illuminate\Support\Facades\DB;

/**
 * An admin marks an incident as read ("Đã đọc") for teams, each with whether it affects the team ("Có", "Không",
 * "Chưa rõ") and an optional note. Any of them stops the team's daily reminder of the incident; "Không" also takes it
 * off the team's "Đang chờ bản vá" list and keeps a fix alone from being sent to the team. It holds only while the
 * exposure the admin saw still covers the team's (see IncidentTeamExposure), and can be corrected at any time; the
 * history keeps every assessment.
 *
 * Only teams told about the incident that still run its technology can be assessed. Each row comes with the exposure
 * hash and the assessment revision the admin was shown, compared under the technology's IncidentLock: a row whose
 * exposure changed since is left as it is ("changed"), and one another admin assessed meanwhile is not overwritten
 * ("conflicts"). An assessment that stopped holding is reset on the way (see IncidentTeam::resetStaleAssessment()).
 */
class AcknowledgeIncidentTeams
{
    /**
     * @param  array<int, array{impact: IncidentImpact, note: string|null, exposure_hash: string, revision: int}>  $rows  Team id => what the admin picked and was shown.
     * @return array{marked: int, changed: int, conflicts: int, skipped: int}
     */
    public function handle(User $user, Incident $incident, array $rows): array
    {
        return DB::transaction(function () use ($user, $incident, $rows): array {
            IncidentLock::take([$incident->technology]);

            $incident = Incident::whereKey($incident->id)->lockForUpdate()->first();
            $result = ['marked' => 0, 'changed' => 0, 'conflicts' => 0, 'skipped' => 0];

            if ($incident === null || $incident->merged_into_id !== null) {
                $result['changed'] = count($rows);

                return $result;
            }

            $notices = $incident->notices()->get();
            $patches = $incident->confirmedPatches($notices);
            $toldTeamIds = IncidentTeamNotice::teamsSentTo($notices->modelKeys());
            $teams = Team::whereKey(array_keys($rows))->orderBy('id')->get();

            foreach ($teams as $team) {
                $picked = $rows[$team->id];
                $runtimes = IncidentTeamExposure::runtimesOf($incident, $team->id);

                if (! $toldTeamIds->contains($team->id) || $runtimes->isEmpty()) {
                    $result['skipped']++;

                    continue;
                }

                [$row] = IncidentTeam::lockFor($incident, $team);
                $exposure = IncidentTeamExposure::of($incident, $notices, $runtimes, $patches);
                $isShownRevision = $row->assessment_revision === $picked['revision'];
                $row->resetStaleAssessment($exposure);

                if (! hash_equals($exposure->hash(), $picked['exposure_hash'])) {
                    $result['changed']++;
                } elseif (! $isShownRevision) {
                    $result['conflicts']++;
                } else {
                    $row->acknowledge($user, $picked['impact'], $picked['note'], $exposure);
                    $result['marked']++;
                }
            }

            $result['skipped'] += count($rows) - $teams->count();

            return $result;
        });
    }
}
