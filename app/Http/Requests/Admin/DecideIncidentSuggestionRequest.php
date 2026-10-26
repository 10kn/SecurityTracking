<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class DecideIncidentSuggestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Accept or reject, with the incident the admin saw the suggested pre-notice in and the one the suggestion pointed
     * at: accepting only moves it while both are still so.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'decision' => ['required', 'in:accept,reject'],
            'incident_id' => ['required_if:decision,accept', 'nullable', 'integer'],
            'target_id' => ['required_if:decision,accept', 'nullable', 'integer'],
        ];
    }

    public function isAccepted(): bool
    {
        return $this->validated('decision') === 'accept';
    }
}
