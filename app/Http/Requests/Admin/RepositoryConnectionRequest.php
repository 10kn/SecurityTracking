<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The name of a repository connection, and its token when it is created. A saved token is only ever replaced
 * (RepositoryConnectionTokenRequest), never shown again.
 */
class RepositoryConnectionRequest extends FormRequest
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
        return [
            'name' => ['required', 'string', 'max:255'],
            ...($this->route('repository_connection') === null ? RepositoryConnectionTokenRequest::tokenRules() : []),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => __('Tên kết nối'),
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
