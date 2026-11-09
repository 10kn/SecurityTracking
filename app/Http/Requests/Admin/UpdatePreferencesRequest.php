<?php

namespace App\Http\Requests\Admin;

use App\Enums\Locale;
use App\Enums\Theme;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePreferencesRequest extends FormRequest
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
            'theme' => ['sometimes', Rule::enum(Theme::class)],
            'system_light_theme' => ['sometimes', Rule::enum(Theme::class)->only(Theme::lightThemes())],
            'locale' => ['sometimes', Rule::enum(Locale::class)],
        ];
    }
}
