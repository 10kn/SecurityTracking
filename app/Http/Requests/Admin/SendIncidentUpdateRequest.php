<?php

namespace App\Http\Requests\Admin;

use App\Models\Incident;
use App\Models\IncidentTeamNotice;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SendIncidentUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The update, the teams the admin confirmed it for (only teams told about the incident), and the id of the request,
     * the same on every retry of the form so the update is never sent twice.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $incident = $this->route('incident');
        $toldTeamIds = $incident instanceof Incident ? IncidentTeamNotice::teamsSentTo($incident->notices()->pluck('id')->all())->all() : [];

        return [
            'message' => ['required', 'string', 'max:2000'],
            'team_ids' => ['required', 'array', 'min:1', 'max:100'],
            'team_ids.*' => ['required', 'integer', 'distinct', Rule::in($toldTeamIds)],
            'request_id' => ['required', 'uuid'],
        ];
    }

    /**
     * @return list<int>
     */
    public function teamIds(): array
    {
        return array_values(array_map(intval(...), $this->validated('team_ids')));
    }
}
