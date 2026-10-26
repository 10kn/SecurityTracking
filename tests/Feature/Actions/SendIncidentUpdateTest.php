<?php

use App\Actions\SendIncidentUpdate;
use App\Actions\SendPreNoticeAlerts;
use App\Enums\ChatNotificationKind;
use App\Enums\ChatThreadRole;
use App\Enums\Severity;
use App\Enums\Technology;
use App\Models\Advisory;
use App\Models\ChatNotification;
use App\Models\Incident;
use App\Models\IncidentTeam;
use App\Models\Team;
use App\Models\User;

use function Pest\Laravel\artisan;

beforeEach(function () {
    $this->travelTo('2026-10-20 21:00:00');
    fakeGoogleChat();
    $this->commerce = Team::factory()->create(['name' => 'Commerce', 'slug' => 'commerce', 'google_chat_webhook_url' => chatWebhook('COMMERCE')]);
    $this->booking = Team::factory()->create(['name' => 'Booking', 'slug' => 'booking', 'google_chat_webhook_url' => chatWebhook('BOOKING')]);
    $this->preNotice = Advisory::factory()->preNotice(Technology::Php)->create(['title' => 'Upcoming Critical PHP Security Release', 'severity' => Severity::Critical, 'published_at' => '2026-10-20 20:32:00']);
    runtimeAt($this->commerce, 'shop-api', Technology::Php, '8.3.35');
    app(SendPreNoticeAlerts::class)->handle();
    $this->incident = $this->preNotice->refresh()->incident;
});

it('replies in the thread of every team told about the incident and records who asked', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    $result = app(SendIncidentUpdate::class)->handle($this->incident, 'Tắt mod_php cho tới khi nâng cấp.', actor: $admin);

    $reply = chatMessagesTo('COMMERCE')->last();
    $notification = ChatNotification::where('kind', ChatNotificationKind::IncidentUpdate)->sole();
    expect($result['queued'])->toBe(1)
        ->and($reply['thread']['threadKey'])->toBe(IncidentTeam::sole()->thread_key)
        ->and(chatJson($reply))->toContain('Cập nhật từ admin', 'Tắt mod_php cho tới khi nâng cấp.', 'Cập nhật · Commerce')
        ->and(chatMessagesTo('BOOKING'))->toHaveCount(0)
        ->and($notification)
        ->dedupe_key->toBe("incident_update:{$result['request_id']}:{$this->commerce->id}")
        ->requested_by->toBe("user:{$admin->id}")
        ->sent_as->toBe(ChatThreadRole::Reply);
});

it('queues an update once per team and request, and a new request again', function () {
    $first = app(SendIncidentUpdate::class)->handle($this->incident, 'Tắt mod_php.', requestId: '0f6b1a52-9d43-4f0e-8a0c-7d1b2c3d4e5f');
    $again = app(SendIncidentUpdate::class)->handle($this->incident, 'Tắt mod_php.', requestId: '0f6b1a52-9d43-4f0e-8a0c-7d1b2c3d4e5f');
    $other = app(SendIncidentUpdate::class)->handle($this->incident, 'Đã có hướng dẫn mới.');

    expect([$first['queued'], $again['queued'], $other['queued']])->toBe([1, 0, 1])
        ->and(ChatNotification::where('kind', ChatNotificationKind::IncidentUpdate)->count())->toBe(2);
});

it('sends a full card that opens the thread to a named team that has none', function () {
    artisan('psm:incident-update', ['incident' => $this->incident->id, 'message' => 'Kiểm tra cả staging.', '--team' => ['booking']])->assertSuccessful();

    $card = chatMessagesTo('BOOKING')->sole();
    expect(chatJson($card))->toContain('Cảnh báo chuẩn bị · Booking', 'Cập nhật từ admin: Kiểm tra cả staging.')->not->toContain('service đang chạy')
        ->and(collect($card['cardsV2'][0]['card']['sections'])->every(fn (array $section): bool => $section['widgets'] !== []))->toBeTrue()
        ->and($card['thread']['threadKey'])->toBe(IncidentTeam::where('team_id', $this->booking->id)->value('thread_key'))
        ->and(ChatNotification::where('kind', ChatNotificationKind::IncidentUpdate)->sole())
        ->requested_by->toBe('cli')
        ->thread_role->toBe(ChatThreadRole::Opener)
        ->and(chatMessagesTo('COMMERCE'))->toHaveCount(1);
});

it('refuses an unknown incident or team, and a merged incident', function () {
    artisan('psm:incident-update', ['incident' => 999999, 'message' => 'x'])->assertFailed();
    artisan('psm:incident-update', ['incident' => $this->incident->id, 'message' => 'x', '--team' => ['nobody']])->assertFailed();

    $this->incident->update(['merged_into_id' => Incident::factory()->create(['technology' => Technology::Php])->id]);
    artisan('psm:incident-update', ['incident' => $this->incident->id, 'message' => 'x'])->assertFailed();

    expect(ChatNotification::where('kind', ChatNotificationKind::IncidentUpdate)->count())->toBe(0);
});
