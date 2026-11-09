<?php

namespace App\Services;

use App\Enums\Severity;
use App\Models\Setting;
use App\Models\SettingChange;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * The settings an admin changes on the "Cài đặt hệ thống" screen instead of the .env. Each one is a value of
 * config/psm.php: the .env stays its default, and a value set on the screen is written over it in the config when the
 * application boots, so the code keeps reading config('psm.…'). Secrets (API keys, the GitHub App, the database) and
 * technical values (source URLs, timeouts, batch sizes) stay in the .env only.
 *
 * A setting has a row only while its value differs from the default; saving the default again removes it, so the
 * setting follows the .env again. Each change is kept in `setting_changes`. The scheduler reads the config again every
 * minute; queue workers keep the config they booted with, so a change asks them to restart (queue:restart).
 */
class SystemSettings
{
    /**
     * Every setting: its name on the screen => its path in config/psm.php and its kind, which says how a value is
     * normalised before it is compared and stored.
     *
     * @var array<string, array{0: string, 1: 'webhook'|'string'|'nullable_string'|'int'|'bool'|'days'|'reminder_days'|'severities'}>
     */
    public const array DEFINITIONS = [
        'admin_webhook' => ['alerts.admin_webhook', 'webhook'],
        'news_webhook' => ['alerts.news_webhook', 'webhook'],
        'alert_locale' => ['alerts.locale', 'string'],
        'scheduler_heartbeat_url' => ['alerts.scheduler_heartbeat_url', 'nullable_string'],

        'digest_time' => ['digest.time', 'string'],
        'digest_days' => ['digest.days', 'days'],
        'digest_timezone' => ['digest.timezone', 'string'],
        'digest_weekly_day' => ['digest.weekly_day', 'int'],
        'eol_warning_days' => ['digest.eol_warning_days', 'int'],
        'eol_reminder_days' => ['digest.eol_reminder_days', 'reminder_days'],

        'immediate_severities' => ['alerts.immediate_severities', 'severities'],
        'immediate_dev_dependencies' => ['alerts.immediate_dev_dependencies', 'bool'],
        'lockfile_summary_threshold' => ['alerts.lockfile_summary_threshold', 'int'],
        'runtime_eol_severity' => ['alerts.runtime_severity.runtime_eol', 'string'],
        'runtime_outdated_security_severity' => ['alerts.runtime_severity.runtime_outdated_security', 'string'],
        'kev_severity' => ['kev.severity', 'string'],

        'deadline_critical_days' => ['alerts.deadline_days.critical', 'int'],
        'deadline_high_days' => ['alerts.deadline_days.high', 'int'],
        'deadline_medium_days' => ['alerts.deadline_days.medium', 'int'],
        'deadline_low_days' => ['alerts.deadline_days.low', 'int'],

        'notice_relevant_days' => ['notices.relevant_days', 'int'],
        'notice_collect_days' => ['notices.collect_days', 'int'],
        'classifier_model' => ['notices.classifier.model', 'string'],
        'classifier_effort' => ['notices.classifier.effort', 'string'],
        'classifier_alert_after_minutes' => ['notices.classifier.alert_after_minutes', 'int'],

        'incident_patch_hold_hours' => ['incidents.patch_hold_hours', 'int'],
        'incident_review_after_days' => ['incidents.review_after_days', 'int'],
        'incident_awaiting_patch_days' => ['incidents.awaiting_patch_days', 'int'],
        'incident_window_before_hours' => ['incidents.release_window_hours.before', 'int'],
        'incident_window_after_hours' => ['incidents.release_window_hours.after', 'int'],

        'github_sync_time' => ['github.sync_time', 'string'],
        'github_sync_timezone' => ['github.timezone', 'string'],
        'catalog_stale_after_minutes' => ['catalog.stale_after_minutes', 'int'],
        'notice_stale_after_minutes' => ['notices.stale_after_minutes', 'int'],
        'kev_stale_after_minutes' => ['kev.stale_after_minutes', 'int'],
        'packages_stale_after_minutes' => ['packages.stale_after_minutes', 'int'],
        'github_stale_after_minutes' => ['github.stale_after_minutes', 'int'],
        'alert_stuck_after_minutes' => ['alerts.stuck_after_minutes', 'int'],
    ];

    /**
     * The models the notice classifier may use; the one of the .env is offered too.
     *
     * @var list<string>
     */
    public const array CLASSIFIER_MODELS = ['claude-haiku-5-5', 'claude-sonnet-5-5', 'claude-opus-5-5'];

    /**
     * @var list<string>
     */
    public const array CLASSIFIER_EFFORTS = ['low', 'medium', 'high'];

    /**
     * The values of config/psm.php before any setting is written over them, i.e. from the .env.
     *
     * @var array<string, mixed>|null
     */
    private ?array $defaults = null;

    /**
     * Writes the stored settings over config/psm.php. Called when the application boots, and again after a change so
     * the rest of the request sees it. Without the settings table (before the migrations ran, or without a database)
     * the .env values stay.
     */
    public function apply(): void
    {
        $defaults = $this->defaults();

        try {
            $stored = Setting::query()->get(['key', 'value'])->pluck('value', 'key')->all();
        } catch (QueryException) {
            return;
        }

        foreach (self::DEFINITIONS as $name => [$path]) {
            config()->set("psm.{$path}", array_key_exists($name, $stored) ? $stored[$name] : $defaults[$name]);
        }
    }

    /**
     * The value of each setting without what was set on the screen, i.e. from the .env.
     *
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return $this->defaults ??= collect(self::DEFINITIONS)
            ->map(fn (array $definition, string $name): mixed => $this->normalize($name, config("psm.{$definition[0]}")))
            ->all();
    }

    /**
     * @return list<string>
     */
    public function classifierModels(): array
    {
        return array_values(array_unique([...self::CLASSIFIER_MODELS, $this->defaults()['classifier_model']]));
    }

    /**
     * The value of each setting in use now.
     *
     * @return array<string, mixed>
     */
    public function current(): array
    {
        return collect(self::DEFINITIONS)
            ->map(fn (array $definition, string $name): mixed => $this->normalize($name, config("psm.{$definition[0]}")))
            ->all();
    }

    /**
     * The settings set on the screen, with the admin who set each one last.
     *
     * @return Collection<string, Setting>
     */
    public function stored(): Collection
    {
        return Setting::query()->with('updatedBy:id,name')->get()->keyBy('key')->toBase();
    }

    /**
     * Saves the settings an admin submitted; webhooks are saved one at a time with updateWebhook().
     *
     * @param  array<string, mixed>  $values
     * @return int How many settings changed.
     */
    public function update(array $values, User $user): int
    {
        $current = $this->current();
        $defaults = $this->defaults();
        $changed = 0;

        DB::transaction(function () use ($values, $user, $current, $defaults, &$changed): void {
            foreach ($values as $name => $value) {
                if (! array_key_exists($name, self::DEFINITIONS) || self::DEFINITIONS[$name][1] === 'webhook') {
                    continue;
                }

                $value = $this->normalize($name, $value);

                if ($value === $current[$name]) {
                    continue;
                }

                $this->store($name, $value, $value === $defaults[$name], $user);
                SettingChange::create(['key' => $name, 'old_value' => $current[$name], 'new_value' => $value, 'user_id' => $user->id]);
                $changed++;
            }
        });

        $this->afterChange($changed);

        return $changed;
    }

    /**
     * Saves a webhook set on the screen, or, with null, removes it so the .env one is used again. Its value is never
     * written in the history.
     */
    public function updateWebhook(string $name, ?string $webhookUrl, User $user): void
    {
        DB::transaction(function () use ($name, $webhookUrl, $user): void {
            $this->store($name, $webhookUrl, $webhookUrl === null, $user);
            SettingChange::create(['key' => $name, 'old_value' => null, 'new_value' => null, 'user_id' => $user->id]);
        });

        $this->afterChange(1);
    }

    /**
     * Where the webhook in use comes from: "settings" when set on the screen, "env" when only the .env sets it, null
     * when there is none.
     */
    public function webhookSource(string $name): ?string
    {
        return match (true) {
            Setting::where('key', $name)->exists() => 'settings',
            filled($this->defaults()[$name]) => 'env',
            default => null,
        };
    }

    private function store(string $name, mixed $value, bool $isDefault, User $user): void
    {
        if ($isDefault) {
            Setting::where('key', $name)->delete();

            return;
        }

        Setting::updateOrCreate(['key' => $name], ['value' => $value, 'updated_by' => $user->id]);
    }

    /**
     * Applies the change to this request and asks the queue workers to restart, so they read it too.
     */
    private function afterChange(int $changed): void
    {
        if ($changed === 0) {
            return;
        }

        $this->apply();
        Artisan::call('queue:restart');
    }

    /**
     * One shape per kind, so a value from the form, the .env or the database compares equal when it means the same:
     * days of the week in order, reminder days from the furthest, severities from the highest.
     */
    private function normalize(string $name, mixed $value): mixed
    {
        return match (self::DEFINITIONS[$name][1]) {
            'webhook', 'nullable_string' => filled($value) ? trim((string) $value) : null,
            'string' => trim((string) $value),
            'int' => (int) $value,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'days' => collect((array) $value)->map(fn (mixed $day): int => (int) $day)->unique()->sort()->values()->all(),
            'reminder_days' => collect((array) $value)->map(fn (mixed $day): int => (int) $day)->unique()->sortDesc()->values()->all(),
            'severities' => collect(Severity::cases())->map(fn (Severity $severity): string => $severity->value)->intersect((array) $value)->values()->all(),
        };
    }
}
