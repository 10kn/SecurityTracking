<?php

use App\Actions\MergeIncidents;
use App\Actions\SendPreNoticeAlerts;
use App\Enums\ChatNotificationKind;
use App\Enums\ChatThreadRole;
use App\Enums\IncidentImpact;
use App\Enums\Severity;
use App\Enums\Technology;
use App\Models\Advisory;
use App\Models\ChatNotification;
use App\Models\Incident;
use App\Models\IncidentTeam;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\get;

beforeEach(function () {
    $this->travelTo('2026-10-20 21:00:00');
    fakeGoogleChat();
    $this->commerce = Team::factory()->create(['name' => 'Commerce', 'google_chat_webhook_url' => chatWebhook('COMMERCE')]);
    $this->preNotice = Advisory::factory()->preNotice(Technology::Php)->create([
        'title' => 'Upcoming Critical PHP Security Release',
        'severity' => Severity::High,
        'aliases' => ['CVE-2026-1001'],
        'published_at' => '2026-10-20 20:32:00',
    ]);
    runtimeAt($this->commerce, 'shop-api', Technology::Php, '8.3.35')->update(['version' => '8.3.35', 'branch' => '8.3']);
    app(SendPreNoticeAlerts::class)->handle();
    $this->incident = $this->preNotice->refresh()->incident;
    $this->row = IncidentTeam::where('incident_id', $this->incident->id)->where('team_id', $this->commerce->id)->sole();
    $this->opener = ChatNotification::findOrFail($this->row->opening_notification_id);
});

/**
 * A reply queued in the row's current thread, delivered or failed.
 *
 * @param  array<string, mixed>  $attributes
 */
function threadReply(IncidentTeam $row, array $attributes = []): ChatNotification
{
    return ChatNotification::factory()->create([
        'team_id' => $row->team_id,
        'kind' => ChatNotificationKind::PreNotice,
        ...$row->threadAttributes(ChatThreadRole::Reply),
        ...$attributes,
    ]);
}

it('counts the teams Google Chat accepted a message for apart from those a card was queued for', function () {
    actingAsAdmin();
    $this->opener->update(['sent_at' => null]);

    get('/admin/incidents')->assertInertia(fn (Assert $page) => $page
        ->where('incidents.data.0.teams_told_count', 1)
        ->where('incidents.data.0.teams_delivered_count', 0)
        ->where('incidents.data.0.has_delivery_failures', false));
    get("/admin/incidents/{$this->incident->id}")->assertInertia(fn (Assert $page) => $page
        ->where('teams.0.delivery.opener.label', 'Đang chờ gửi')
        ->where('teams.0.delivery.last_sent', null)
        ->where('teams.0.delivery.failures', [])
        ->where('teams.0.delivery.limit', null));

    $this->opener->update(['sent_at' => now()]);

    get('/admin/incidents')->assertInertia(fn (Assert $page) => $page->where('incidents.data.0.teams_delivered_count', 1));
    get("/admin/incidents/{$this->incident->id}")->assertInertia(fn (Assert $page) => $page
        ->where('teams.0.delivery.opener', ['label' => 'Đã gửi', 'tone' => 'success', 'at' => '21/10/2026 04:00 GMT+7'])
        ->where('teams.0.delivery.latest', null)
        ->where('teams.0.delivery.last_sent', '21/10/2026 04:00 GMT+7'));
});

it('shows an opener that failed while a reply that opened the thread was delivered', function () {
    actingAsAdmin();
    $this->opener->update(['sent_at' => null, 'failed_at' => now(), 'last_error' => '403 Forbidden: the app was removed from the space']);
    $this->travel(1)->hour();
    $reply = threadReply($this->row, ['sent_as' => ChatThreadRole::Opener, 'sent_at' => now()]);

    get('/admin/incidents')->assertInertia(fn (Assert $page) => $page
        ->where('incidents.data.0.teams_delivered_count', 1)
        ->where('incidents.data.0.has_delivery_failures', true));
    get("/admin/incidents/{$this->incident->id}")->assertInertia(fn (Assert $page) => $page
        ->where('teams.0.delivery.opener.label', 'Gửi lỗi')
        ->where('teams.0.delivery.latest.label', 'Đã gửi')
        ->where('teams.0.delivery.last_sent', '21/10/2026 05:00 GMT+7')
        ->where('teams.0.delivery.failures_count', 1)
        ->where('teams.0.delivery.failures', [[
            'id' => $this->opener->id,
            'error' => '403 Forbidden: the app was removed from the space',
            'failed' => '21/10/2026 04:00 GMT+7',
            'command' => "php artisan psm:resend-notification {$this->opener->id}",
        ]]));

    expect($reply->id)->toBeGreaterThan($this->opener->id);
});

it('offers no resend of a failed alert while the admin ruling the team out holds, but still of a failed admin update', function () {
    actingAsAdmin();
    $alert = threadReply($this->row, ['failed_at' => now(), 'last_error' => 'Team chưa có webhook.']);
    $update = threadReply($this->row, ['kind' => ChatNotificationKind::IncidentUpdate, 'failed_at' => now(), 'last_error' => 'Team chưa có webhook.']);
    $row = assessIncident($this->incident, $this->commerce, IncidentImpact::No);

    get("/admin/incidents/{$this->incident->id}")->assertInertia(fn (Assert $page) => $page
        ->where('teams.0.delivery.failures.0.id', $alert->id)
        ->where('teams.0.delivery.failures.0.command', null)
        ->where('teams.0.delivery.failures.1.id', $update->id)
        ->where('teams.0.delivery.failures.1.command', "php artisan psm:resend-notification {$update->id}"));

    runtimeAt($this->commerce, 'booking-api', Technology::Php, '8.4.1')->update(['version' => '8.4.1', 'branch' => '8.4']);

    expect($row->refresh()->impact)->toBe(IncidentImpact::No);

    get("/admin/incidents/{$this->incident->id}")->assertInertia(fn (Assert $page) => $page
        ->where('teams.0.delivery.failures.0.command', "php artisan psm:resend-notification {$alert->id}"));
});

it('keeps an earlier failure after a later message was delivered, until that message is sent again', function () {
    actingAsAdmin();
    $failed = threadReply($this->row, ['failed_at' => now(), 'last_error' => str_repeat('x', 300)]);
    $this->travel(1)->hour();
    threadReply($this->row, ['sent_at' => now()]);

    get("/admin/incidents/{$this->incident->id}")->assertInertia(fn (Assert $page) => $page
        ->where('teams.0.delivery.latest.label', 'Đã gửi')
        ->where('teams.0.delivery.failures.0.id', $failed->id)
        ->where('teams.0.delivery.failures.0.error', fn (string $error): bool => mb_strlen($error) <= 163));
    get('/admin/incidents')->assertInertia(fn (Assert $page) => $page->where('incidents.data.0.has_delivery_failures', true));

    $failed->update(['failed_at' => null]);

    get("/admin/incidents/{$this->incident->id}")->assertInertia(fn (Assert $page) => $page
        ->where('teams.0.delivery.failures', [])
        ->where('teams.0.delivery.pending_count', 1));

    $failed->update(['sent_at' => now()]);

    get("/admin/incidents/{$this->incident->id}")->assertInertia(fn (Assert $page) => $page->where('teams.0.delivery.failures', []));
    get('/admin/incidents')->assertInertia(fn (Assert $page) => $page->where('incidents.data.0.has_delivery_failures', false));
});

it('reads only the current thread once the team webhook changed, without calling its old failures fixed', function () {
    actingAsAdmin();
    $failed = threadReply($this->row, ['failed_at' => now(), 'last_error' => '404 Not Found']);
    $this->commerce->update(['google_chat_webhook_url' => chatWebhook('COMMERCE2')]);
    $this->row->moveTo(chatWebhook('COMMERCE2'));

    expect($this->row->refresh()->generation)->toBe(2);

    get('/admin/incidents')->assertInertia(fn (Assert $page) => $page
        ->where('incidents.data.0.teams_told_count', 1)
        ->where('incidents.data.0.teams_delivered_count', 0)
        ->where('incidents.data.0.has_delivery_failures', false));
    get("/admin/incidents/{$this->incident->id}")->assertInertia(fn (Assert $page) => $page
        ->where('teams.0.delivery.opener', null)
        ->where('teams.0.delivery.failures', [])
        ->where('teams.0.delivery.limit', 'Chưa có dữ liệu chuyển phát trong thread hiện tại (webhook của team đã đổi).'));

    expect($failed->refresh())->failed_at->not->toBeNull()->sent_at->toBeNull();
});

it('reads no delivery of the old thread as soon as the team webhook changed, before its next message moves the thread', function () {
    actingAsAdmin();
    threadReply($this->row, ['failed_at' => now(), 'last_error' => '404 Not Found']);
    $this->commerce->update(['google_chat_webhook_url' => chatWebhook('COMMERCE2')]);

    get('/admin/incidents')->assertInertia(fn (Assert $page) => $page
        ->where('incidents.data.0.teams_delivered_count', 0)
        ->where('incidents.data.0.has_delivery_failures', false));
    get("/admin/incidents/{$this->incident->id}")->assertInertia(fn (Assert $page) => $page
        ->where('teams.0.delivery.opener', null)
        ->where('teams.0.thread.status.label', 'Thread của webhook cũ')
        ->where('teams.0.thread.is_old', true)
        ->where('teams.0.delivery.limit', 'Webhook của team đã đổi: dữ liệu thread cũ không tính cho webhook hiện tại; tin tiếp theo sẽ mở thread mới.'));

    expect($this->row->refresh()->generation)->toBe(1);
});

it('lists the first failures of each thread and counts them all', function () {
    actingAsAdmin();
    $booking = Team::factory()->create(['name' => 'Booking', 'google_chat_webhook_url' => chatWebhook('BOOKING')]);
    runtimeAt($booking, 'booking-api', Technology::Php, '8.4.1');
    [$bookingRow] = IncidentTeam::lockFor($this->incident, $booking);
    $failures = collect(range(1, 12))->map(fn () => threadReply($this->row, ['failed_at' => now(), 'last_error' => '500 Internal Server Error']));
    $bookingFailures = collect(range(1, 11))->map(fn () => threadReply($bookingRow, ['failed_at' => now(), 'last_error' => '404 Not Found']));

    get("/admin/incidents/{$this->incident->id}")->assertInertia(fn (Assert $page) => $page
        ->where('teams.0.name', 'Booking')
        ->where('teams.0.delivery.failures_count', 11)
        ->where('teams.0.delivery.failures', fn ($items): bool => collect($items)->pluck('id')->all() === $bookingFailures->take(10)->pluck('id')->all())
        ->where('teams.1.delivery.failures_count', 12)
        ->where('teams.1.delivery.failures', fn ($items): bool => collect($items)->pluck('id')->all() === $failures->take(10)->pluck('id')->all()));
});

it('reads the thread a merged incident handed over', function () {
    actingAsAdmin();
    $failed = threadReply($this->row, ['failed_at' => now(), 'last_error' => '500 Internal Server Error']);
    $into = Incident::factory()->create(['technology' => Technology::Php, 'title' => 'PHP security release', 'first_published_at' => now()]);

    app(MergeIncidents::class)->handle($this->incident, $into, User::factory()->admin()->create());

    expect(IncidentTeam::where('incident_id', $into->id)->sole()->thread_key)->toBe($this->row->thread_key);

    get('/admin/incidents?technology=php')->assertInertia(fn (Assert $page) => $page
        ->where('incidents.data.0.id', $into->id)
        ->where('incidents.data.0.teams_delivered_count', 1)
        ->where('incidents.data.0.has_delivery_failures', true));
    get("/admin/incidents/{$into->id}")->assertInertia(fn (Assert $page) => $page
        ->where('teams.0.delivery.opener.label', 'Đã gửi')
        ->where('teams.0.delivery.failures.0.id', $failed->id));
});

it('says what cannot be told of a team told before incident threads', function () {
    actingAsAdmin();
    $legacy = Team::factory()->create(['name' => 'Legacy']);
    runtimeAt($legacy, 'legacy-api', Technology::Php, '8.3.30');
    ChatNotification::factory()->for($legacy)->sent()->create(['kind' => ChatNotificationKind::PreNotice, 'dedupe_key' => "pre_notice:{$this->preNotice->id}:{$legacy->id}:high"]);

    get('/admin/incidents')->assertInertia(fn (Assert $page) => $page
        ->where('incidents.data.0.teams_told_count', 2)
        ->where('incidents.data.0.teams_delivered_count', 1));
    get("/admin/incidents/{$this->incident->id}")->assertInertia(fn (Assert $page) => $page
        ->where('teams.1.name', 'Legacy')
        ->where('teams.1.delivery.opener', null)
        ->where('teams.1.delivery.latest', null)
        ->where('teams.1.delivery.limit', 'Team được báo bằng tin trước khi có thread: không biết từ đây tin đó đã tới chưa.'));
});

it('leaves the delivery out for a team never told', function () {
    actingAsAdmin();
    $booking = Team::factory()->create(['name' => 'Booking']);
    runtimeAt($booking, 'booking-api', Technology::Php, '8.4.1');

    get("/admin/incidents/{$this->incident->id}")->assertInertia(fn (Assert $page) => $page
        ->where('teams.0.name', 'Booking')
        ->where('teams.0.delivery', null));
});
