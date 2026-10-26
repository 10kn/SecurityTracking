<?php

namespace App\Console\Commands;

use App\Actions\SendIncidentUpdate;
use App\Models\Incident;
use App\Models\Team;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Signature('psm:incident-update {incident : The id of the incident} {message : The update, e.g. a workaround} {--team=* : Only these teams, by id or slug}')]
#[Description('Post an urgent update about an incident in the Google Chat thread of every team told about it')]
class PostIncidentUpdate extends Command
{
    /**
     * Without --team the update goes to every team told about the incident (see SendIncidentUpdate). A team that is
     * not found stops the command before anything is sent.
     */
    public function handle(SendIncidentUpdate $sendIncidentUpdate): int
    {
        $incident = Incident::find($this->argument('incident'));

        if ($incident === null) {
            $this->components->error(__('Không có sự kiện với id này.'));

            return self::FAILURE;
        }

        $teamIds = [];

        foreach ($this->option('team') as $key) {
            $team = Team::where('slug', $key)->when(ctype_digit((string) $key), fn ($query) => $query->orWhere('id', (int) $key))->first();

            if ($team === null) {
                $this->components->error(__('Không có team :team.', ['team' => $key]));

                return self::FAILURE;
            }

            $teamIds[] = $team->id;
        }

        try {
            $result = $sendIncidentUpdate->handle($incident, (string) $this->argument('message'), $teamIds);
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(__('Đã đưa :count tin cập nhật vào hàng đợi (mã yêu cầu :id).', ['count' => $result['queued'], 'id' => $result['request_id']]));

        return self::SUCCESS;
    }
}
