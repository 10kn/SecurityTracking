<?php

namespace App\Http\Requests\Admin;

use App\Models\Team;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAdminWebhookRequest extends FormRequest
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
            'google_chat_webhook_url' => ['required', 'string', 'max:2048', 'url', 'starts_with:'.Team::GOOGLE_CHAT_WEBHOOK_PREFIX],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'google_chat_webhook_url' => __('Webhook URL'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'google_chat_webhook_url.starts_with' => __('URL phải bắt đầu bằng https://chat.googleapis.com/v1/spaces/'),
        ];
    }
}
