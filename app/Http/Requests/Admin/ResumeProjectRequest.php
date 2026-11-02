<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ResumeProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Whether the team hears what it missed while the project was paused ("Gửi") or not ("Không gửi"), with an
     * optional note.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'notify' => ['required', 'boolean'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'notify' => __('Thông báo cho team'),
            'note' => __('Ghi chú'),
        ];
    }

    public function note(): ?string
    {
        return filled($this->validated('note')) ? trim($this->validated('note')) : null;
    }
}
