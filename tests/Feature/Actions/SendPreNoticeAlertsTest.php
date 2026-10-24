<?php

use App\Actions\SendPreNoticeAlerts;
use App\Enums\ChatNotificationKind;
use App\Models\Advisory;
use App\Models\ChatNotification;
use App\Models\Finding;
use App\Models\Team;
use App\Models\Technology;

beforeEach(function () {
    $this->travelTo('2026-10-20 21:00:00');
    fakeGoogleChat();
    $this->php = Technology::factory()->php()->create();
    $this->commerce = Team::factory()->create(['name' => 'Commerce', 'google_chat_webhook_url' => chatWebhook('COMMERCE')]);
    $this->booking = Team::factory()->create(['name' => 'Booking', 'google_chat_webhook_url' => chatWebhook('BOOKING')]);
    $this->preNotice = Advisory::factory()->preNotice($this->php)->create([
        'title' => 'Upcoming Critical PHP Security Release',
        'summary' => 'It addresses two critical vulnerabilities.',
        'cvss_score' => 9.2,
        'url' => 'https://www.php.net/archive/2026.php#2026-10-20-1',
        'published_at' => '2026-10-20 20:32:00',
    ]);
});

it('sends one card to each team running the technology, listing its services with their versions', function () {
    runtimeAt($this->commerce, 'shop-api', $this->php, '8.3.35');
    runtimeAt($this->commerce, 'shop-admin', $this->php, '8.4.26');
    runtimeAt($this->booking, 'booking-api', $this->php, '8.2.30');
    $analytics = Team::factory()->create(['google_chat_webhook_url' => chatWebhook('ANALYTICS')]);
    runtimeAt($analytics, 'events', Technology::factory()->nodejs()->create(), '22.20.0');

    expect(app(SendPreNoticeAlerts::class)->handle())->toBe(2);

    $card = chatMessagesTo('COMMERCE')->sole();
    expect($card['text'])->toBe('[Critical] Upcoming Critical PHP Security Release')
        ->and(chatJson($card))->toContain(
            'Cảnh báo chuẩn bị · Commerce',
            'It addresses two critical vulnerabilities.',
            'Nguồn: php.net · Đăng lúc 20/10/2026 20:32 UTC · CVSS 9.2',
            '2 service đang chạy PHP',
            'shop-api', 'PHP 8.3.35', 'shop-admin', 'PHP 8.4.26', 'Mở service',
            'Mở nguồn tin', 'https://www.php.net/archive/2026.php#2026-10-20-1',
        )->not->toContain('booking-api')
        ->and(chatJson(chatMessagesTo('BOOKING')->sole()))->toContain('booking-api', 'PHP 8.2.30')
        ->and(chatMessagesTo('ANALYTICS'))->toHaveCount(0)
        ->and(ChatNotification::where('kind', ChatNotificationKind::PreNotice)->pluck('dedupe_key')->all())
        ->toEqualCanonicalizing(["pre_notice:{$this->preNotice->id}:{$this->commerce->id}", "pre_notice:{$this->preNotice->id}:{$this->booking->id}"])
        ->and(Finding::count())->toBe(0);
});

it('sends nothing when it runs again', function () {
    runtimeAt($this->commerce, 'shop-api', $this->php, '8.3.35');

    app(SendPreNoticeAlerts::class)->handle();
    $this->travel(15)->minutes();

    expect(app(SendPreNoticeAlerts::class)->handle())->toBe(0)
        ->and(chatMessagesTo('COMMERCE'))->toHaveCount(1);
});

it('sends to a team that starts running the technology while the pre-notice is fresh', function () {
    runtimeAt($this->commerce, 'shop-api', $this->php, '8.3.35');
    app(SendPreNoticeAlerts::class)->handle();

    $this->travel(1)->day();
    runtimeAt($this->booking, 'booking-api', $this->php, '8.4.26');
    app(SendPreNoticeAlerts::class)->handle();

    expect(chatMessagesTo('COMMERCE'))->toHaveCount(1)
        ->and(chatMessagesTo('BOOKING'))->toHaveCount(1);
});

it('does not send pre-notices older than the configured age, so the first poll does not replay a feed', function () {
    runtimeAt($this->commerce, 'shop-api', $this->php, '8.3.35');

    $this->travelTo('2026-10-23 20:33:00');
    expect(app(SendPreNoticeAlerts::class)->handle())->toBe(0);

    config(['psm.notices.max_age_hours' => 96]);
    expect(app(SendPreNoticeAlerts::class)->handle())->toBe(1);
});

it('ignores runtimes of deployments that are no longer current', function () {
    runtimeAt($this->commerce, 'shop-api', $this->php, '8.3.35')->deployment->update(['is_current' => false]);

    expect(app(SendPreNoticeAlerts::class)->handle())->toBe(0);
});
