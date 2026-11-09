<?php

use App\Actions\SendOsSecurityNews;
use App\Enums\ChatNotificationKind;
use App\Enums\LinuxDistribution;
use App\Enums\Severity;
use App\Models\Advisory;
use App\Models\ChatNotification;

beforeEach(function () {
    $this->travelTo('2026-11-09 10:00:00');
    fakeGoogleChat();
});

it('sends an advisory at an immediate severity to the security news space once', function () {
    $advisory = Advisory::factory()->osAdvisory(LinuxDistribution::Ubuntu)->create([
        'external_id' => 'USN-8999-1',
        'title' => 'USN-8999-1: Pillow vulnerabilities',
        'summary' => 'Several security issues were fixed in Pillow.',
        'severity' => Severity::High,
        'aliases' => ['CVE-2026-54058', 'CVE-2026-54059'],
        'os_releases' => ['24.04', '22.04'],
        'fixed_packages' => [['release' => '24.04', 'name' => 'pillow', 'version' => '10.2.0-1ubuntu1.4'], ['release' => '22.04', 'name' => 'pillow', 'version' => '9.0.1-1ubuntu0.6']],
        'url' => 'https://ubuntu.com/security/notices/USN-8999-1',
        'published_at' => '2026-11-04 15:49:54',
    ]);

    expect(app(SendOsSecurityNews::class)->handle())->toBe(1)
        ->and(app(SendOsSecurityNews::class)->handle())->toBe(0);

    $message = chatMessagesTo('NEWS')->sole();
    expect($message['text'])->toBe('🟠 [High] USN-8999-1: Pillow vulnerabilities')
        ->and(chatJson($message))->toContain(
            'Tin bảo mật · Ubuntu',
            'Several security issues were fixed in Pillow.',
            'Ubuntu 24.04, 22.04',
            'pillow 10.2.0-1ubuntu1.4 (24.04), pillow 9.0.1-1ubuntu0.6 (22.04)',
            'CVE-2026-54058, CVE-2026-54059',
            'Mở nguồn tin',
            'Xem tin hệ điều hành',
        )
        ->and(chatMessagesTo('ADMIN'))->toBeEmpty()
        ->and(ChatNotification::sole())->kind->toBe(ChatNotificationKind::SecurityNews)->team_id->toBeNull()
        ->and($advisory->refresh()->news_notification_id)->toBe(ChatNotification::sole()->id);
});

it('follows the severities the system settings send right away', function () {
    config(['psm.alerts.immediate_severities' => ['critical']]);
    Advisory::factory()->osAdvisory()->create(['severity' => Severity::High, 'published_at' => now()->subDay()]);
    $critical = Advisory::factory()->osAdvisory(LinuxDistribution::AmazonLinux2023)->create(['severity' => Severity::Critical, 'published_at' => now()->subDay()]);

    expect(app(SendOsSecurityNews::class)->handle())->toBe(1)
        ->and(chatMessagesTo('NEWS')->sole()['text'])->toBe("🔴 [Critical] {$critical->title}");
});

it('sends an advisory below the immediate severities when CISA KEV lists one of its CVE ids', function () {
    Advisory::factory()->osAdvisory(LinuxDistribution::Debian)->create([
        'title' => 'DSA-6616-1: linux - security update',
        'severity' => Severity::Unknown,
        'aliases' => ['CVE-2026-100080'],
        'known_exploited' => [['cve' => 'CVE-2026-100080', 'name' => 'Linux Kernel', 'date_added' => '2026-11-08', 'due_date' => '2026-11-29', 'listed' => true]],
        'published_at' => now()->subDay(),
    ]);

    expect(app(SendOsSecurityNews::class)->handle())->toBe(1)
        ->and(chatJson(chatMessagesTo('NEWS')->sole()))->toContain('CISA KEV ghi nhận CVE-2026-100080 đã bị khai thác trong thực tế');
});

it('sends neither the history of a first poll, nor old advisories, nor those below the immediate severities', function () {
    Advisory::factory()->osAdvisory()->create(['severity' => Severity::Critical, 'is_backfill' => true, 'published_at' => now()->subDay()]);
    Advisory::factory()->osAdvisory()->create(['severity' => Severity::Critical, 'published_at' => now()->subDays(15)]);
    Advisory::factory()->osAdvisory()->create(['severity' => Severity::Medium, 'published_at' => now()->subDay()]);
    Advisory::factory()->osAdvisory()->create(['severity' => Severity::Unknown, 'published_at' => now()->subDay()]);

    expect(app(SendOsSecurityNews::class)->handle())->toBe(0)
        ->and(ChatNotification::count())->toBe(0);
});

it('sends an advisory once its severity rises to an immediate one', function () {
    $advisory = Advisory::factory()->osAdvisory()->create(['severity' => Severity::Medium, 'published_at' => now()->subDay()]);
    app(SendOsSecurityNews::class)->handle();

    $advisory->update(['severity' => Severity::High]);

    expect(app(SendOsSecurityNews::class)->handle())->toBe(1);
});

it('sends to the admin space when security news has no space of its own, and queues nothing without either', function () {
    Advisory::factory()->osAdvisory()->create(['severity' => Severity::High, 'published_at' => now()->subDay()]);
    config(['psm.alerts.news_webhook' => null, 'psm.alerts.admin_webhook' => null]);

    expect(app(SendOsSecurityNews::class)->handle())->toBe(0);

    config(['psm.alerts.admin_webhook' => chatWebhook('ADMIN')]);

    expect(app(SendOsSecurityNews::class)->handle())->toBe(1)
        ->and(chatMessagesTo('ADMIN'))->toHaveCount(1);
});
