<?php

use App\Actions\SendPreNoticeAlerts;
use App\Enums\ChatNotificationKind;
use App\Enums\Severity;
use App\Enums\SeverityBasis;
use App\Enums\Technology;
use App\Models\Advisory;
use App\Models\ChatNotification;
use App\Models\Finding;
use App\Models\Incident;
use App\Models\IncidentTeamNotice;
use App\Models\Team;

beforeEach(function () {
    $this->travelTo('2026-10-20 21:00:00');
    fakeGoogleChat();
    $this->php = Technology::Php;
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
    runtimeAt($analytics, 'events', Technology::Nodejs, '22.20.0');

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
        ->each->toStartWith("pre_notice:incident:{$this->preNotice->refresh()->incident_id}:")
        ->and(IncidentTeamNotice::where('advisory_id', $this->preNotice->id)->get(['team_id', 'severity'])->map(fn ($told) => [$told->team_id, $told->severity])->all())
        ->toEqualCanonicalizing([[$this->commerce->id, Severity::Critical], [$this->booking->id, Severity::Critical]])
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

it('still sends a pre-notice a few days later, e.g. after an outage, but not once it is older than the configured days', function () {
    runtimeAt($this->commerce, 'shop-api', $this->php, '8.3.35');

    $this->travelTo('2026-11-04 00:00:00');
    expect(app(SendPreNoticeAlerts::class)->handle())->toBe(0);

    config(['psm.notices.relevant_days' => 20]);
    expect(app(SendPreNoticeAlerts::class)->handle())->toBe(1);
});

it('does not send what a feed listed on its first poll', function () {
    runtimeAt($this->commerce, 'shop-api', $this->php, '8.3.35');
    $this->preNotice->update(['is_backfill' => true]);

    expect(app(SendPreNoticeAlerts::class)->handle())->toBe(0);
});

it('leaves medium and low pre-notices to the daily digest', function (Severity $severity) {
    runtimeAt($this->commerce, 'shop-api', $this->php, '8.3.35');
    $this->preNotice->update(['severity' => $severity]);

    expect(app(SendPreNoticeAlerts::class)->handle())->toBe(0);
})->with([Severity::Medium, Severity::Low]);

it('sends a pre-notice of unknown severity right away and says why it has none', function (?string $classifiedBy, string $reason) {
    runtimeAt($this->commerce, 'shop-api', $this->php, '8.3.35');
    $this->preNotice->update(['title' => 'PHP security notice', 'severity' => Severity::Unknown, 'cvss_score' => null, 'classified_by' => $classifiedBy]);

    expect(app(SendPreNoticeAlerts::class)->handle())->toBe(1);

    $card = chatMessagesTo('COMMERCE')->sole();
    expect($card['text'])->toBe('PHP security notice')
        ->and(chatJson($card))->toContain($reason);
})->with([
    'not classified' => [null, 'Chưa phân loại được mức độ. Đọc tin gốc để đánh giá.'],
    'classified' => ['claude-haiku-5-5', 'Hãng chưa nêu mức độ.'],
]);

it('ignores runtimes of deployments that are no longer current', function () {
    runtimeAt($this->commerce, 'shop-api', $this->php, '8.3.35')->deployment->update(['is_current' => false]);

    expect(app(SendPreNoticeAlerts::class)->handle())->toBe(0);
});

it('shows what the classifier read: the summary in the alert locale, the planned release and the affected branches', function () {
    runtimeAt($this->commerce, 'shop-api', $this->php, '8.3.35');
    $this->preNotice->update([
        'localized_summary' => 'PHP sẽ vá hai lỗ hổng nghiêm trọng.',
        'planned_release_at' => '2026-10-21 14:00:00',
        'affected_branches' => ['8.3', '8.4'],
    ]);

    app(SendPreNoticeAlerts::class)->handle();

    expect(chatJson(chatMessagesTo('COMMERCE')->sole()))
        ->toContain('PHP sẽ vá hai lỗ hổng nghiêm trọng.', 'Dự kiến phát hành 21/10/2026 14:00 UTC · Nhánh bị ảnh hưởng: 8.3, 8.4')
        ->not->toContain('It addresses two critical vulnerabilities.');
});

it('does not send pre-notices the classifier dismissed', function () {
    runtimeAt($this->commerce, 'shop-api', $this->php, '8.3.35');
    $this->preNotice->update(['is_dismissed' => true]);

    expect(app(SendPreNoticeAlerts::class)->handle())->toBe(0);
});

it('tells the team again once when the severity rises, but not when the vendor only edits the text', function (Severity $from) {
    runtimeAt($this->commerce, 'shop-api', $this->php, '8.3.35');
    $this->preNotice->update(['severity' => $from]);
    app(SendPreNoticeAlerts::class)->handle();

    $this->preNotice->update(['severity' => Severity::Critical]);
    app(SendPreNoticeAlerts::class)->handle();
    $this->preNotice->update(['localized_summary' => 'PHP sẽ vá hai lỗ hổng nghiêm trọng.']);
    app(SendPreNoticeAlerts::class)->handle();

    $messages = chatMessagesTo('COMMERCE');
    expect($messages)->toHaveCount(2)
        ->and($messages->last()['text'])->toBe('[Critical] Upcoming Critical PHP Security Release')
        ->and(chatJson($messages->last()))->toContain("Mức độ tăng từ {$from->getLabel()} lên Critical.");
})->with([Severity::Unknown, Severity::High]);

it('reads the severity of cards sent before it was part of the dedupe key from their title', function (string $title, int $sent) {
    runtimeAt($this->commerce, 'shop-api', $this->php, '8.3.35');
    ChatNotification::factory()->for($this->commerce)->sent()->create([
        'kind' => ChatNotificationKind::PreNotice,
        'dedupe_key' => "pre_notice:{$this->preNotice->id}:{$this->commerce->id}",
        'payload' => ['text' => $title],
    ]);

    expect(app(SendPreNoticeAlerts::class)->handle())->toBe($sent);
})->with([
    'sent as critical' => ['[Critical] Upcoming Critical PHP Security Release', 0],
    'sent as high' => ['[High] Upcoming Critical PHP Security Release', 1],
    'sent without a severity' => ['Upcoming Critical PHP Security Release', 1],
]);

it('holds pre-notices below critical the classifier has not read yet, when asked', function () {
    runtimeAt($this->commerce, 'shop-api', $this->php, '8.3.35');
    $high = Advisory::factory()->preNotice($this->php)->create(['severity' => Severity::High, 'published_at' => now()->subHour()]);

    expect(app(SendPreNoticeAlerts::class)->handle(holdUnclassified: true))->toBe(1)
        ->and(chatMessagesTo('COMMERCE')->sole()['text'])->toBe('[Critical] Upcoming Critical PHP Security Release');

    $high->update(['classified_by' => 'claude-haiku-5-5']);

    expect(app(SendPreNoticeAlerts::class)->handle(holdUnclassified: true))->toBe(1);
});

it('shows what gave the severity', function () {
    runtimeAt($this->commerce, 'shop-api', $this->php, '8.3.35');
    $this->preNotice->update(['severity_basis' => SeverityBasis::Cvss]);

    app(SendPreNoticeAlerts::class)->handle();

    expect(chatJson(chatMessagesTo('COMMERCE')->sole()))->toContain('CVSS 9.2 · Mức theo điểm CVSS');
});

it('sends an incident right away when one of its pre-notices has no severity, even next to a low one', function () {
    runtimeAt($this->commerce, 'shop-api', $this->php, '8.3.35');
    $this->preNotice->update(['severity' => Severity::Low, 'aliases' => ['CVE-2026-1001']]);
    Advisory::factory()->preNotice($this->php)->create(['title' => 'PHP security notice', 'severity' => Severity::Unknown, 'aliases' => ['CVE-2026-1001'], 'published_at' => now()]);

    expect(app(SendPreNoticeAlerts::class)->handle())->toBe(1)
        ->and(Incident::count())->toBe(1)
        ->and(chatMessagesTo('COMMERCE')->sole()['text'])->toBe('[Low] Upcoming Critical PHP Security Release');
});

it('sends no other card for news that only adds to an incident, and one when the incident rises', function () {
    runtimeAt($this->commerce, 'shop-api', $this->php, '8.3.35');
    $this->preNotice->update(['severity' => Severity::High, 'aliases' => ['CVE-2026-1001']]);
    app(SendPreNoticeAlerts::class)->handle();

    $this->travel(1)->hour();
    Advisory::factory()->preNotice($this->php)->create(['title' => 'PHP security advisory', 'severity' => Severity::High, 'aliases' => ['CVE-2026-1001'], 'published_at' => now()]);

    expect(app(SendPreNoticeAlerts::class)->handle())->toBe(0);

    Advisory::factory()->preNotice($this->php)->create(['title' => 'PHP security advisory, now critical', 'severity' => Severity::Critical, 'aliases' => ['CVE-2026-1001'], 'published_at' => now()]);

    expect(app(SendPreNoticeAlerts::class)->handle())->toBe(1);

    $card = chatMessagesTo('COMMERCE')->last();
    expect($card['text'])->toBe('[Critical] PHP security advisory, now critical')
        ->and(chatJson($card))->toContain('Mức độ tăng từ High lên Critical.', 'Tin khác của cùng sự kiện', '[High] Upcoming Critical PHP Security Release', '[High] PHP security advisory');
});

it('says for each service whether the vendor named its branch as affected', function () {
    runtimeAt($this->commerce, 'shop-api', $this->php, '8.3.35')->update(['branch' => '8.3']);
    runtimeAt($this->commerce, 'shop-admin', $this->php, '8.5.11')->update(['branch' => '8.5']);
    runtimeAt($this->commerce, 'shop-legacy', $this->php, '8.2.30')->update(['branch' => '8.2']);
    $this->preNotice->update(['affected_branches' => ['8.3'], 'unaffected_branches' => ['8.5']]);

    app(SendPreNoticeAlerts::class)->handle();

    expect(chatJson(chatMessagesTo('COMMERCE')->sole()))->toContain(
        'PHP 8.3.35 · Phạm vi: đã xác nhận',
        'PHP 8.5.11 · Phạm vi: đã xác nhận không ảnh hưởng',
        'PHP 8.2.30 · Phạm vi: chưa xác định',
    );
});

it('tells the team again when the incident rises, even while a pre-notice without severity already makes it urgent', function () {
    runtimeAt($this->commerce, 'shop-api', $this->php, '8.3.35');
    $this->preNotice->update(['severity' => Severity::Low, 'aliases' => ['CVE-2026-1001']]);
    Advisory::factory()->preNotice($this->php)->create(['title' => 'PHP security notice', 'severity' => Severity::Unknown, 'aliases' => ['CVE-2026-1001'], 'published_at' => now()]);
    app(SendPreNoticeAlerts::class)->handle();

    $this->preNotice->update(['severity' => Severity::Medium]);

    expect(app(SendPreNoticeAlerts::class)->handle())->toBe(1)
        ->and(chatMessagesTo('COMMERCE')->last()['text'])->toBe('[Medium] Upcoming Critical PHP Security Release');
});

it('records as told only the pre-notices the card shows', function () {
    config(['psm.alerts.max_listed_items' => 1]);
    runtimeAt($this->commerce, 'shop-api', $this->php, '8.3.35');
    $this->preNotice->update(['aliases' => ['CVE-2026-1001']]);
    $shown = Advisory::factory()->preNotice($this->php)->create(['title' => 'PHP security advisory', 'severity' => Severity::High, 'aliases' => ['CVE-2026-1001'], 'published_at' => now()]);
    Advisory::factory()->preNotice($this->php)->create(['title' => 'PHP security FAQ', 'severity' => Severity::Low, 'aliases' => ['CVE-2026-1001'], 'published_at' => now()]);

    app(SendPreNoticeAlerts::class)->handle();

    expect(IncidentTeamNotice::pluck('advisory_id')->all())->toEqualCanonicalizing([$this->preNotice->id, $shown->id]);
});
