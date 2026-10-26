<?php

namespace App\Http\Requests\Admin;

use App\Enums\IncidentImpact;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AcknowledgeIncidentTeamsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The teams the admin was shown and picked, each with its impact, an optional note, and the exposure hash and
     * assessment revision shown; never "every team", so a team whose exposure changes while the page is open is not
     * marked by mistake.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'teams' => ['required', 'array', 'max:100'],
            'teams.*.id' => ['required', 'integer', 'distinct'],
            'teams.*.impact' => ['required', Rule::enum(IncidentImpact::class)],
            'teams.*.note' => ['nullable', 'string', 'max:1000'],
            'teams.*.exposure_hash' => ['required', 'string', 'size:64'],
            'teams.*.revision' => ['required', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<int, array{impact: IncidentImpact, note: string|null, exposure_hash: string, revision: int}> Team id => what the admin picked and was shown.
     */
    public function assessments(): array
    {
        return collect($this->validated('teams'))
            ->mapWithKeys(fn (array $team): array => [(int) $team['id'] => [
                'impact' => IncidentImpact::from($team['impact']),
                'note' => filled($team['note'] ?? null) ? trim($team['note']) : null,
                'exposure_hash' => $team['exposure_hash'],
                'revision' => (int) $team['revision'],
            ]])
            ->all();
    }
}
