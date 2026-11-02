<?php

use App\Actions\SendSecurityNews;
use App\Enums\ChatNotificationKind;
use App\Enums\Severity;
use App\Enums\SeverityBasis;
use App\Enums\Technology;
use App\Models\Advisory;
use App\Models\ChatNotification;
use App\Models\Incident;
use App\Models\Team;
use App\Services\Notices\NoticeClassification;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->travelTo('2026-10-20 21:00:00');
    config(['psm.alerts.news_webhook' => chatWebhook('NEWS')]);
    $this->incident = Incident::factory()->create(['technology' => Technology::Php]);
    $this->preNotice = Advisory::factory()->preNotice(Technology::Php)->create([
        'incident_id' => $this->incident->id,
        'title' => 'Upcoming Critical PHP Security Release',
        'summary' => 'It addresses two critical vulnerabilities.',
        'url' => 'https://www.php.net/archive/2026.php#2026-10-20-1',
        'published_at' => '2026-10-20 20:32:00',
    ]);
});

it('sends security news to the news space even when no team runs the technology', function () {
    fakeGoogleChat();
    expect(app(SendSecurityNews::class)->handle())->toBe(1);

    $message = chatMessagesTo('NEWS')->sole();
    expect($message['text'])->toBe('[Critical] Upcoming Critical PHP Security Release')
        ->and(chatJson($message))->toContain('Tin bảo mật · PHP', 'It addresses two critical vulnerabilities.', 'Chưa team nào chạy PHP.', 'Mở nguồn tin', 'Xem incident')
        ->and(chatMessagesTo('ADMIN'))->toBeEmpty()
        ->and($this->preNotice->refresh()->news_notification_id)->toBe(ChatNotification::sole()->id)
        ->and(ChatNotification::sole())->kind->toBe(ChatNotificationKind::SecurityNews)->team_id->toBeNull();
});

it('names the teams that run the technology', function () {
    fakeGoogleChat();
    $commerce = Team::factory()->create(['name' => 'Commerce', 'google_chat_webhook_url' => chatWebhook('COMMERCE')]);
    $booking = Team::factory()->create(['name' => 'Booking', 'google_chat_webhook_url' => chatWebhook('BOOKING')]);
    runtimeAt($commerce, 'shop-api', Technology::Php, '8.3.35');
    runtimeAt($commerce, 'shop-admin', Technology::Php, '8.4.26');
    runtimeAt($booking, 'booking-api', Technology::Php, '8.2.30');

    app(SendSecurityNews::class)->handle();

    expect(chatJson(chatMessagesTo('NEWS')->sole()))->toContain('Team đang chạy PHP: Booking, Commerce');
});

it('sends security news to the admin space when it has no space of its own', function () {
    fakeGoogleChat();
    config(['psm.alerts.news_webhook' => null]);

    app(SendSecurityNews::class)->handle();

    expect(chatMessagesTo('ADMIN'))->toHaveCount(1);
});

it('queues nothing while neither space is configured, and sends the news once one is', function () {
    fakeGoogleChat();
    config(['psm.alerts.news_webhook' => null, 'psm.alerts.admin_webhook' => null]);

    expect(app(SendSecurityNews::class)->handle())->toBe(0)
        ->and(ChatNotification::count())->toBe(0);

    config(['psm.alerts.news_webhook' => chatWebhook('NEWS')]);

    expect(app(SendSecurityNews::class)->handle())->toBe(1);
});

it('leaves out the history of a first poll, what the classifier dismissed and old news', function () {
    fakeGoogleChat();
    $this->preNotice->update(['is_backfill' => true]);
    Advisory::factory()->preNotice(Technology::Php)->create(['incident_id' => $this->incident->id, 'is_dismissed' => true]);
    Advisory::factory()->preNotice(Technology::Php)->create(['incident_id' => $this->incident->id, 'published_at' => now()->subDays(15)]);

    expect(app(SendSecurityNews::class)->handle())->toBe(0)
        ->and(chatMessagesTo('NEWS'))->toBeEmpty();
});

it('waits for the classifier, unless a CVSS score makes the news Critical', function () {
    fakeGoogleChat();
    classifyNoticesWith(fn () => new NoticeClassification(true, Severity::High));
    $this->preNotice->update(['severity' => Severity::High, 'severity_basis' => SeverityBasis::Vendor]);
    $critical = Advisory::factory()->preNotice(Technology::Php)->create(['incident_id' => $this->incident->id, 'severity_basis' => SeverityBasis::Cvss, 'cvss_score' => 9.8]);

    expect(app(SendSecurityNews::class)->handle())->toBe(1)
        ->and($critical->refresh()->news_notification_id)->not->toBeNull();

    $this->preNotice->update(['classified_by' => 'claude-haiku-5-5']);

    expect(app(SendSecurityNews::class)->handle())->toBe(1);
});

it('sends the news of one incident in one thread, each once, and nothing for an edit', function () {
    fakeGoogleChat();
    Advisory::factory()->preNotice(Technology::Php)->create(['incident_id' => $this->incident->id, 'title' => 'PHP 8.3.36 Released', 'published_at' => '2026-10-20 20:50:00']);

    expect(app(SendSecurityNews::class)->handle())->toBe(2);

    $this->preNotice->update(['summary' => 'It addresses three critical vulnerabilities.']);

    expect(app(SendSecurityNews::class)->handle())->toBe(0);

    $messages = chatMessagesTo('NEWS');
    expect($messages)->toHaveCount(2)
        ->and($messages->pluck('thread.threadKey')->unique()->all())->toBe([SendSecurityNews::threadKeyFor($this->incident)])
        ->and($messages->pluck('text')->all())->toBe(['[Critical] Upcoming Critical PHP Security Release', '[Critical] PHP 8.3.36 Released'])
        ->and(chatJson($messages[1]))->toContain('Tin khác của cùng sự kiện', 'Upcoming Critical PHP Security Release');
});

it('tells the admin space when the news space cannot be reached', function () {
    Http::fake([chatWebhook('NEWS').'*' => Http::response('Not found', 404)]);
    fakeGoogleChat();

    app(SendSecurityNews::class)->handle();

    expect(ChatNotification::where('kind', ChatNotificationKind::SecurityNews)->sole())->failed_at->not->toBeNull()
        ->and(chatJson(chatMessagesTo('ADMIN')->sole()))->toContain('Không gửi được tin bảo mật tới space tin', 'Google Chat trả lỗi HTTP 404.');
});
