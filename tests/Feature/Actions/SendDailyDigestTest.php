<?php

use App\Actions\SendDailyDigest;
use App\Enums\ChatNotificationKind;
use App\Enums\FindingEventType;
use App\Enums\RuntimeStatus;
use App\Enums\Severity;
use App\Models\Deployment;
use App\Models\Finding;
use App\Models\Lockfile;
use App\Models\Project;
use App\Models\Runtime;
use App\Models\Service;
use App\Models\Team;
use App\Models\Technology;

beforeEach(function () {
    fakeGoogleChat();
    $this->travelTo('2026-10-24 01:00:00');
    $this->team = Team::factory()->create(['name' => 'Commerce', 'google_chat_webhook_url' => chatWebhook('COMMERCE')]);
    $this->deployment = Deployment::factory()->for(Service::factory()->for(Project::factory()->for($this->team))->state(['name' => 'shop-api']))->create();
});

function digestFinding(Deployment $deployment, string $title, array $attributes = [], string $openedAt = '2026-10-23 10:00:00'): Finding
{
    $finding = Finding::factory()->for($deployment)->create(['title' => $title, ...$attributes]);
    $finding->events()->create(['type' => FindingEventType::Opened])->forceFill(['created_at' => $openedAt])->save();

    return $finding;
}

it('sends each team one card with new, still open and closed findings', function () {
    digestFinding($this->deployment, 'PHP 8.1 hết hỗ trợ');
    digestFinding($this->deployment, 'DEMO-GHSA-0001 trong guzzlehttp/guzzle', ['severity' => Severity::Medium], '2026-10-01 10:00:00');
    digestFinding($this->deployment, 'Node.js 20 hết hỗ trợ', ['state' => 'resolved', 'resolved_at' => '2026-10-23 12:00:00']);
    digestFinding($this->deployment, 'Ruby 3.1 hết hỗ trợ', ['state' => 'resolved', 'resolved_at' => '2026-10-20 12:00:00']);

    expect(app(SendDailyDigest::class)->handle())->toBe(1);

    $card = chatJson(chatMessagesTo('COMMERCE')->sole());
    expect($card)->toContain('Bản tổng hợp bảo mật 24/10/2026', 'Mới: 1 · Đang mở: 2 · Đã tự đóng: 1', 'PHP 8.1 hết hỗ trợ', '[Medium] DEMO-GHSA-0001', 'Node.js 20 hết hỗ trợ')
        ->not->toContain('Ruby 3.1');
});

it('counts runtimes with missing data or a newer release, and lists those reaching end of life soon', function () {
    $php = syncedTechnology('php');
    Runtime::factory()->for($this->deployment)->for(Technology::factory())->create(['status' => RuntimeStatus::Unknown]);
    Runtime::factory()->for($this->deployment)->for($php)->create(['raw_version' => '8.4.26', 'branch' => '8.4', 'status' => RuntimeStatus::Outdated]);
    $php->branches()->where('branch', '8.4')->update(['security_support_until' => '2026-11-15']);

    app(SendDailyDigest::class)->handle();

    expect(chatJson(chatMessagesTo('COMMERCE')->sole()))
        ->toContain('Runtime thiếu dữ liệu: 1 · Runtime có bản mới hơn: 1', 'Sắp hết hỗ trợ', 'PHP 8.4.26 hết hỗ trợ từ 15/11/2026');
});

it('sends nothing to a team with nothing to report', function () {
    expect(app(SendDailyDigest::class)->handle())->toBe(0)
        ->and(chatMessagesTo('COMMERCE'))->toHaveCount(0);
});

it('sends a team one digest per day', function () {
    digestFinding($this->deployment, 'PHP 8.1 hết hỗ trợ');

    app(SendDailyDigest::class)->handle();
    app(SendDailyDigest::class)->handle();

    expect(chatMessagesTo('COMMERCE'))->toHaveCount(1)
        ->and($this->team->chatNotifications()->sole()->kind)->toBe(ChatNotificationKind::Digest);
});

it('counts new findings since the previous digest', function () {
    digestFinding($this->deployment, 'PHP 8.1 hết hỗ trợ');
    app(SendDailyDigest::class)->handle();

    $this->travelTo('2026-10-25 01:00:00');
    app(SendDailyDigest::class)->handle();

    expect(chatJson(chatMessagesTo('COMMERCE')->last()))->toContain('Đang mở: 1')->not->toContain('Mới: 1');
});

it('counts services without a lockfile and lockfiles that cannot be read', function () {
    Lockfile::factory()->for($this->deployment)->withContent('composer.lock', '{"packages": [')->create(['parsed_hash' => hash('sha256', '{"packages": ['), 'parse_error' => 'Not valid JSON: Syntax error.']);
    Deployment::factory()->for(Service::factory()->for(Project::factory()->for($this->team)))->create();

    expect(app(SendDailyDigest::class)->handle())->toBe(1)
        ->and(chatJson(chatMessagesTo('COMMERCE')->sole()))->toContain('Service chưa có lockfile: 1 · Lockfile không đọc được: 1');
});

it('sends no digest only because a service has no lockfile', function () {
    expect(app(SendDailyDigest::class)->handle())->toBe(0);
});
