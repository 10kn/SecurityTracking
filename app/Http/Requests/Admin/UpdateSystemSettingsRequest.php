<?php

namespace App\Http\Requests\Admin;

use App\Enums\Locale;
use App\Enums\Severity;
use App\Services\SystemSettings;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Every setting of the "Cài đặt hệ thống" screen but the webhooks, which are saved one at a time after a test message.
 */
class UpdateSystemSettingsRequest extends FormRequest
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
        $severities = [Severity::Critical->value, Severity::High->value, Severity::Medium->value, Severity::Low->value];

        return [
            'alert_locale' => ['required', Rule::enum(Locale::class)],
            'scheduler_heartbeat_url' => ['nullable', 'string', 'max:2048', 'url', 'starts_with:https://'],

            'digest_time' => ['required', 'date_format:H:i'],
            'digest_days' => ['required', 'array', 'min:1'],
            'digest_days.*' => ['integer', 'between:0,6', 'distinct'],
            'digest_timezone' => ['required', 'timezone:all'],
            'digest_weekly_day' => ['required', 'integer', 'between:0,6'],
            'eol_warning_days' => ['required', 'integer', 'between:1,365'],
            'eol_reminder_days' => ['required', 'array', 'min:1', 'max:5'],
            'eol_reminder_days.*' => ['integer', 'between:1,365', 'distinct'],

            'immediate_severities' => ['present', 'array'],
            'immediate_severities.*' => [Rule::in($severities), 'distinct'],
            'immediate_dev_dependencies' => ['required', 'boolean'],
            'lockfile_summary_threshold' => ['required', 'integer', 'between:0,100'],
            'runtime_eol_severity' => ['required', Rule::in($severities)],
            'runtime_outdated_security_severity' => ['required', Rule::in($severities)],
            'kev_severity' => ['required', Rule::in($severities)],

            'deadline_critical_days' => ['required', 'integer', 'between:1,365'],
            'deadline_high_days' => ['required', 'integer', 'between:1,365'],
            'deadline_medium_days' => ['required', 'integer', 'between:1,365'],
            'deadline_low_days' => ['required', 'integer', 'between:1,365'],

            'notice_relevant_days' => ['required', 'integer', 'between:1,365'],
            'notice_collect_days' => ['required', 'integer', 'between:1,3650', 'gte:notice_relevant_days'],
            'classifier_model' => ['required', Rule::in(app(SystemSettings::class)->classifierModels())],
            'classifier_effort' => ['required', Rule::in(SystemSettings::CLASSIFIER_EFFORTS)],
            'classifier_alert_after_minutes' => ['required', 'integer', 'between:5,1440'],

            'incident_patch_hold_hours' => ['required', 'integer', 'between:0,168'],
            'incident_review_after_days' => ['required', 'integer', 'between:1,365'],
            'incident_awaiting_patch_days' => ['required', 'integer', 'between:1,365'],
            'incident_window_before_hours' => ['required', 'integer', 'between:0,720'],
            'incident_window_after_hours' => ['required', 'integer', 'between:0,720'],

            'github_sync_time' => ['required', 'date_format:H:i'],
            'github_sync_timezone' => ['required', 'timezone:all'],
            'catalog_stale_after_minutes' => ['required', 'integer', 'between:15,10080'],
            'notice_stale_after_minutes' => ['required', 'integer', 'between:15,10080'],
            'kev_stale_after_minutes' => ['required', 'integer', 'between:15,10080'],
            'packages_stale_after_minutes' => ['required', 'integer', 'between:15,10080'],
            'github_stale_after_minutes' => ['required', 'integer', 'between:15,10080'],
            'alert_stuck_after_minutes' => ['required', 'integer', 'between:5,1440'],
        ];
    }

    /**
     * The weekly summary replaces the digest of one of its days, so that day must be one of them.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $days = array_map('intval', (array) $this->input('digest_days', []));

                if (! $validator->errors()->hasAny(['digest_days', 'digest_weekly_day']) && ! in_array((int) $this->input('digest_weekly_day'), $days, true)) {
                    $validator->errors()->add('digest_weekly_day', __('Ngày gửi bản tổng hợp tuần phải là một trong các ngày nhận tin.'));
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
            'alert_locale' => __('Ngôn ngữ tin nhắn'),
            'scheduler_heartbeat_url' => __('URL heartbeat'),
            'digest_time' => __('Giờ gửi'),
            'digest_days' => __('Ngày nhận tin'),
            'digest_days.*' => __('Ngày nhận tin'),
            'digest_timezone' => __('Múi giờ'),
            'digest_weekly_day' => __('Ngày gửi bản tổng hợp tuần'),
            'eol_warning_days' => __('Cảnh báo EOL trước'),
            'eol_reminder_days' => __('Các mốc nhắc EOL'),
            'eol_reminder_days.*' => __('Mốc nhắc EOL'),
            'immediate_severities' => __('Gửi ngay khi mức độ'),
            'immediate_severities.*' => __('Mức độ'),
            'immediate_dev_dependencies' => __('Dev dependency'),
            'lockfile_summary_threshold' => __('Ngưỡng gộp thẻ tóm tắt'),
            'runtime_eol_severity' => __('Runtime hết vòng đời (EOL)'),
            'runtime_outdated_security_severity' => __('Runtime thiếu bản vá bảo mật'),
            'kev_severity' => __('Lỗ hổng trong CISA KEV'),
            'deadline_critical_days' => 'Critical',
            'deadline_high_days' => 'High',
            'deadline_medium_days' => 'Medium',
            'deadline_low_days' => 'Low',
            'notice_relevant_days' => __('Số ngày tin còn hiệu lực'),
            'notice_collect_days' => __('Số ngày lưu tin'),
            'classifier_model' => __('Model phân loại'),
            'classifier_effort' => __('Mức suy luận'),
            'classifier_alert_after_minutes' => __('Báo tin chưa phân loại sau'),
            'incident_patch_hold_hours' => __('Giữ thẻ "thiếu bản vá"'),
            'incident_review_after_days' => __('Nhắc review sau'),
            'incident_awaiting_patch_days' => __('Theo dõi "đang chờ bản vá"'),
            'incident_window_before_hours' => __('Trước bản phát hành'),
            'incident_window_after_hours' => __('Sau bản phát hành'),
            'github_sync_time' => __('Giờ đồng bộ'),
            'github_sync_timezone' => __('Múi giờ'),
            'catalog_stale_after_minutes' => __('Danh mục phiên bản'),
            'notice_stale_after_minutes' => __('Tin từ vendor'),
            'kev_stale_after_minutes' => 'CISA KEV',
            'packages_stale_after_minutes' => __('Lỗ hổng package'),
            'github_stale_after_minutes' => 'GitHub',
            'alert_stuck_after_minutes' => __('Tin chờ gửi trong hàng đợi'),
        ];
    }
}
