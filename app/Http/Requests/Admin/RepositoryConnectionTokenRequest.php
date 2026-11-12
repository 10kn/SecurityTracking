<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A new personal access token for a repository connection. GitHub is asked about it before it is saved
 * (RepositoryConnectionTokenController).
 */
class RepositoryConnectionTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return self::tokenRules();
    }

    /**
     * A classic token ("ghp_…") or a fine-grained one ("github_pat_…"): letters, digits and underscores only, so it
     * cannot carry anything into a header.
     *
     * @return array<string, list<string>>
     */
    public static function tokenRules(): array
    {
        return [
            'token' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9_]+$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'token' => __('Token'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'token.regex' => __('Token chỉ gồm chữ, số và dấu gạch dưới, ví dụ ghp_… hoặc github_pat_….'),
        ];
    }
}
