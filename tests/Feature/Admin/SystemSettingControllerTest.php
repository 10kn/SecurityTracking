<?php

use App\Models\Setting;
use App\Models\SettingChange;
use App\Models\User;
use App\Services\SystemSettings;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Laravel\delete;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\put;

const ADMIN_WEBHOOK = 'https://chat.googleapis.com/v1/spaces/ADMIN/messages?key=admin-key&token=admin-token';

/**
 * The settings as the screen submits them, with some changed.
 *
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function submittedSettings(array $changes = []): array
{
    $current = app(SystemSettings::class)->current();
    unset($current['admin_webhook'], $current['news_webhook']);

    return [...$current, ...$changes];
}

/**
 * Reads the .env defaults again, as a new process would, after a test changed them with config().
 *
 * @param  array<string, mixed>  $config
 */
function bootSettingsWithEnv(array $config): void
{
    config($config);
    app()->forgetInstance(SystemSettings::class);
    app(SystemSettings::class)->apply();
}

it('shows every setting with its .env default and nothing changed yet', function () {
    actingAsAdmin();

    get('/admin/settings')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/edit')
            ->where('settings.digest_time', config('psm.digest.time'))
            ->where('defaults.digest_time', config('psm.digest.time'))
            ->where('settings.deadline_critical_days', config('psm.alerts.deadline_days.critical'))
            ->missing('settings.admin_webhook')
            ->where('changed', [])
            ->where('history', [])
            ->where('webhooks.admin.source', null));
});

it('is only for admins', function () {
    actingAs(User::factory()->create());

    get('/admin/settings')->assertForbidden();
    put('/admin/settings', submittedSettings())->assertForbidden();
});

it('saves a changed setting over the .env and uses it right away', function () {
    $admin = actingAsAdmin();

    put('/admin/settings', submittedSettings(['digest_time' => '07:30', 'digest_days' => [5, 1, 3], 'digest_weekly_day' => 1, 'deadline_high_days' => 10]))
        ->assertRedirect('/admin/settings')
        ->assertInertiaFlash('toast.message', 'Đã lưu 3 cài đặt.');

    expect(config('psm.digest.time'))->toBe('07:30')
        ->and(config('psm.digest.days'))->toBe([1, 3, 5])
        ->and(config('psm.alerts.deadline_days.high'))->toBe(10)
        ->and(Setting::pluck('key')->sort()->values()->all())->toBe(['deadline_high_days', 'digest_days', 'digest_time'])
        ->and(SettingChange::firstWhere('key', 'digest_time'))
        ->old_value->toBe('08:00')
        ->new_value->toBe('07:30')
        ->user_id->toBe($admin->id);
});

it('writes the stored settings over config/psm.php when the application boots', function () {
    Setting::create(['key' => 'digest_time', 'value' => '06:00']);
    Setting::create(['key' => 'immediate_severities', 'value' => ['critical']]);
    Setting::create(['key' => 'immediate_dev_dependencies', 'value' => true]);

    bootSettingsWithEnv([]);

    expect(config('psm.digest.time'))->toBe('06:00')
        ->and(config('psm.alerts.immediate_severities'))->toBe(['critical'])
        ->and(config('psm.alerts.immediate_dev_dependencies'))->toBeTrue();
});

it('removes a setting saved back to its .env value, so it follows the .env again', function () {
    actingAsAdmin();
    put('/admin/settings', submittedSettings(['eol_warning_days' => 45]));

    put('/admin/settings', submittedSettings(['eol_warning_days' => app(SystemSettings::class)->defaults()['eol_warning_days']]));

    assertDatabaseMissing('settings', ['key' => 'eol_warning_days']);
    expect(config('psm.digest.eol_warning_days'))->toBe(app(SystemSettings::class)->defaults()['eol_warning_days'])
        ->and(SettingChange::where('key', 'eol_warning_days')->count())->toBe(2);
});

it('changes nothing when nothing differs', function () {
    actingAsAdmin();

    put('/admin/settings', submittedSettings())->assertInertiaFlash('toast.message', 'Không có thay đổi nào.');

    expect(Setting::count())->toBe(0)->and(SettingChange::count())->toBe(0);
});

it('treats lists in another order as the same value', function () {
    actingAsAdmin();
    $defaults = app(SystemSettings::class)->defaults();

    put('/admin/settings', submittedSettings([
        'digest_days' => array_reverse($defaults['digest_days']),
        'eol_reminder_days' => array_reverse($defaults['eol_reminder_days']),
        'immediate_severities' => array_reverse($defaults['immediate_severities']),
    ]))->assertInertiaFlash('toast.message', 'Không có thay đổi nào.');
});

it('rejects invalid settings', function (array $changes, string $field) {
    actingAsAdmin();

    put('/admin/settings', submittedSettings($changes))->assertSessionHasErrors($field);

    expect(Setting::count())->toBe(0);
})->with([
    'a time that is not HH:MM' => [['digest_time' => '7h'], 'digest_time'],
    'no digest day' => [['digest_days' => []], 'digest_days'],
    'a day out of the week' => [['digest_days' => [1, 7], 'digest_weekly_day' => 1], 'digest_days.1'],
    'a weekly day that is not a digest day' => [['digest_days' => [1, 2], 'digest_weekly_day' => 3], 'digest_weekly_day'],
    'an unknown timezone' => [['digest_timezone' => 'Mars/Olympus'], 'digest_timezone'],
    'an unknown severity' => [['immediate_severities' => ['urgent']], 'immediate_severities.0'],
    'a deadline of zero days' => [['deadline_critical_days' => 0], 'deadline_critical_days'],
    'news kept shorter than it counts' => [['notice_relevant_days' => 30, 'notice_collect_days' => 10], 'notice_collect_days'],
    'an unknown classifier model' => [['classifier_model' => 'gpt-5'], 'classifier_model'],
    'a heartbeat url over http' => [['scheduler_heartbeat_url' => 'http://hc-ping.com/abc'], 'scheduler_heartbeat_url'],
    'an unknown alert language' => [['alert_locale' => 'en'], 'alert_locale'],
]);

it('saves the admin webhook only after google chat accepts the test message, encrypted and masked', function () {
    actingAsAdmin();
    Http::preventStrayRequests();
    Http::fake(['https://chat.googleapis.com/v1/spaces/ADMIN/messages*' => Http::response(['name' => 'spaces/ADMIN/messages/1'])]);

    put('/admin/settings/webhooks/admin', ['google_chat_webhook_url' => ADMIN_WEBHOOK])
        ->assertRedirect('/admin/settings')
        ->assertInertiaFlash('toast.type', 'success');

    Http::assertSent(fn ($request) => $request->url() === ADMIN_WEBHOOK && str_contains($request['text'], 'cảnh báo lỗi hệ thống'));
    expect(config('psm.alerts.admin_webhook'))->toBe(ADMIN_WEBHOOK)
        ->and(DB::table('settings')->where('key', 'admin_webhook')->value('value'))->not->toContain('admin-token')
        ->and(SettingChange::firstWhere('key', 'admin_webhook'))->old_value->toBeNull()->new_value->toBeNull();
});

it('shows only a masked admin webhook', function () {
    actingAsAdmin();
    Setting::create(['key' => 'admin_webhook', 'value' => ADMIN_WEBHOOK]);
    app(SystemSettings::class)->apply();

    get('/admin/settings')
        ->assertInertia(fn (Assert $page) => $page
            ->where('webhooks.admin.masked_url', 'https://chat.googleapis.com/v1/spaces/ADMI…/messages?key=••••')
            ->where('webhooks.admin.source', 'settings'))
        ->assertDontSee('admin-token');
});

it('keeps the old webhook when google chat rejects the test message', function () {
    actingAsAdmin();
    Http::preventStrayRequests();
    Http::fake(['https://chat.googleapis.com/*' => Http::response('Not found', 404)]);

    put('/admin/settings/webhooks/news', ['google_chat_webhook_url' => ADMIN_WEBHOOK])
        ->assertSessionHasErrors(['google_chat_webhook_url' => 'Gửi thử thất bại, webhook chưa được lưu: Google Chat trả lỗi HTTP 404.']);

    expect(Setting::count())->toBe(0);
});

it('rejects a webhook that is not google chat and an unknown channel', function () {
    actingAsAdmin();
    Http::preventStrayRequests();

    put('/admin/settings/webhooks/admin', ['google_chat_webhook_url' => 'https://example.com/hook'])->assertSessionHasErrors('google_chat_webhook_url');
    put('/admin/settings/webhooks/teams', ['google_chat_webhook_url' => ADMIN_WEBHOOK])->assertNotFound();
});

it('goes back to the .env webhook', function () {
    actingAsAdmin();
    bootSettingsWithEnv(['psm.alerts.admin_webhook' => 'https://chat.googleapis.com/v1/spaces/ENV/messages?key=k&token=t']);
    Setting::create(['key' => 'admin_webhook', 'value' => ADMIN_WEBHOOK]);
    app(SystemSettings::class)->apply();

    delete('/admin/settings/webhooks/admin')->assertRedirect('/admin/settings');

    expect(Setting::count())->toBe(0)
        ->and(config('psm.alerts.admin_webhook'))->toBe('https://chat.googleapis.com/v1/spaces/ENV/messages?key=k&token=t')
        ->and(app(SystemSettings::class)->webhookSource('admin_webhook'))->toBe('env');
});

it('sends a test message to the webhook in use', function () {
    actingAsAdmin();
    Http::preventStrayRequests();
    Http::fake(['https://chat.googleapis.com/v1/spaces/ENV/messages*' => Http::response(['name' => 'spaces/ENV/messages/1'])]);
    bootSettingsWithEnv(['psm.alerts.news_webhook' => 'https://chat.googleapis.com/v1/spaces/ENV/messages?key=k&token=t']);

    post('/admin/settings/webhooks/news/test-message')->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Gửi thử thành công.']);
    post('/admin/settings/webhooks/admin/test-message')->assertInertiaFlash('toast.description', 'Chưa có webhook.');

    Http::assertSentCount(1);
});

it('lists the latest changes first', function () {
    $admin = actingAsAdmin();
    put('/admin/settings', submittedSettings(['digest_time' => '07:00']));
    put('/admin/settings', submittedSettings(['kev_severity' => 'high']));

    get('/admin/settings')->assertInertia(fn (Assert $page) => $page
        ->has('history', 2)
        ->where('history.0.key', 'kev_severity')
        ->where('history.0.new_value', 'high')
        ->where('history.0.user', $admin->name)
        ->where('changed.digest_time.by', $admin->name)
        ->etc());
});
