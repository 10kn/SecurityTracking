<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AdminWebhook;
use App\Enums\Locale;
use App\Enums\Severity;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateSystemSettingsRequest;
use App\Models\Setting;
use App\Models\SettingChange;
use App\Models\Team;
use App\Services\SystemSettings;
use App\Support\LocalTime;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The "Cài đặt hệ thống" screen: the settings of config/psm.php an admin may change instead of the .env. See
 * SystemSettings.
 */
class SystemSettingController extends Controller
{
    /**
     * How many changes the history lists, the latest first.
     */
    private const int HISTORY_LIMIT = 20;

    public function edit(SystemSettings $settings): Response
    {
        $webhookNames = array_map(fn (AdminWebhook $webhook): string => $webhook->settingName(), AdminWebhook::cases());
        $current = $settings->current();
        $stored = $settings->stored();

        return Inertia::render('settings/edit', [
            'settings' => array_diff_key($current, array_flip($webhookNames)),
            'defaults' => array_diff_key($settings->defaults(), array_flip($webhookNames)),
            'changed' => $stored->except($webhookNames)->map(fn (Setting $setting): array => $this->changedBy($setting))->all(),
            'webhooks' => collect(AdminWebhook::cases())->mapWithKeys(fn (AdminWebhook $webhook): array => [$webhook->value => [
                'masked_url' => Team::maskGoogleChatWebhookUrl($current[$webhook->settingName()]),
                'source' => $settings->webhookSource($webhook->settingName()),
                'changed' => $stored->has($webhook->settingName()) ? $this->changedBy($stored[$webhook->settingName()]) : null,
            ]])->all(),
            'history' => SettingChange::query()->with('user:id,name')->latest('id')->limit(self::HISTORY_LIMIT)->get()->map(fn (SettingChange $change): array => [
                'id' => $change->id,
                'key' => $change->key,
                'old_value' => $change->old_value,
                'new_value' => $change->new_value,
                'user' => $change->user?->name,
                'at' => LocalTime::format($change->created_at),
            ]),
            'options' => [
                'locales' => array_map(fn (Locale $locale): array => ['value' => $locale->value, 'label' => $locale->getLabel()], Locale::cases()),
                'severities' => array_map(fn (Severity $severity): array => ['value' => $severity->value, 'label' => $severity->getLabel()], [Severity::Critical, Severity::High, Severity::Medium, Severity::Low]),
                'timezones' => DateTimeZone::listIdentifiers(),
                'classifier_models' => $settings->classifierModels(),
                'classifier_efforts' => SystemSettings::CLASSIFIER_EFFORTS,
            ],
        ]);
    }

    public function update(UpdateSystemSettingsRequest $request, SystemSettings $settings): RedirectResponse
    {
        $changed = $settings->update($request->validated(), $request->user());

        Inertia::flash('toast', $changed === 0
            ? ['type' => 'success', 'message' => __('Không có thay đổi nào.')]
            : ['type' => 'success', 'message' => __('Đã lưu :count cài đặt.', ['count' => $changed])]);

        return to_route('admin.settings.edit');
    }

    /**
     * @return array{by: string|null, at: string|null}
     */
    private function changedBy(Setting $setting): array
    {
        return ['by' => $setting->updatedBy?->name, 'at' => LocalTime::format($setting->updated_at)];
    }
}
