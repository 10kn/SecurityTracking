<?php

namespace App\Http\Requests\Admin;

use App\Enums\NotificationTarget;
use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateProjectNotificationTargetRequest extends FormRequest
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
            'notification_target' => ['required', Rule::enum(NotificationTarget::class)],
        ];
    }

    /**
     * A project goes to its own webhook only once one is saved (see ProjectWebhookController).
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                /** @var Project $project */
                $project = $this->route('project');

                if ($this->input('notification_target') === NotificationTarget::Project->value && blank($project->google_chat_webhook_url)) {
                    $validator->errors()->add('notification_target', __('Thêm webhook riêng cho project trước.'));
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'notification_target' => __('Kênh thông báo'),
        ];
    }
}
