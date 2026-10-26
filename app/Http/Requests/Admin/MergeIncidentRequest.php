<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class MergeIncidentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The incident to merge into. That it is current, another one and of the same technology is checked under the
     * lock by MergeIncidents.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'into_id' => ['required', 'integer', 'exists:incidents,id'],
        ];
    }
}
