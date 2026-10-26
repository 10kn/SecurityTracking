<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AcknowledgeFindingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The findings the admin was shown and picked, each with the version shown; never "everything matching a filter",
     * so a finding that appears or changes while the page is open is not marked by mistake.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'findings' => ['required', 'array', 'max:100'],
            'findings.*.id' => ['required', 'integer', 'distinct'],
            'findings.*.version' => ['nullable', 'integer'],
        ];
    }

    /**
     * @return array<int, int|null> Finding id => version.
     */
    public function findingVersions(): array
    {
        return collect($this->validated('findings'))
            ->mapWithKeys(fn (array $finding): array => [(int) $finding['id'] => isset($finding['version']) ? (int) $finding['version'] : null])
            ->all();
    }
}
