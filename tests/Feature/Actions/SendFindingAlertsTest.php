<?php

use App\Actions\SendFindingAlerts;
use App\Enums\ChatNotificationKind;
use App\Enums\Severity;
use App\Models\ChatNotification;
use App\Models\Deployment;
use App\Models\Finding;
use App\Models\Project;
use App\Models\Service;
use App\Models\Team;

function findingAt(Team $team, string $service, array $attributes = []): Finding
{
    $deployment = Deployment::factory()->for(Service::factory()->for(Project::factory()->for($team))->state(['name' => $service]))->create();

    return Finding::factory()->for($deployment)->create(['group_key' => 'runtime_eol:1:8.1', ...$attributes]);
}

beforeEach(function () {
    fakeGoogleChat();
    $this->team = Team::factory()->create(['google_chat_webhook_url' => chatWebhook('COMMERCE')]);
});

it('sends one card per team and issue, listing every affected service', function () {
    findingAt($this->team, 'shop-api');
    findingAt($this->team, 'shop-web');
    findingAt($this->team, 'legacy-cms', ['group_key' => 'runtime_eol:1:7.4', 'title' => 'PHP 7.4 hết hỗ trợ']);

    expect(app(SendFindingAlerts::class)->handle())->toBe(2);

    $messages = chatMessagesTo('COMMERCE');
    expect($messages)->toHaveCount(2)
        ->and(chatJson($messages))->toContain('shop-api', 'shop-web', 'legacy-cms')
        ->and(Finding::whereNull('notified_at')->count())->toBe(0)
        ->and(ChatNotification::where('kind', ChatNotificationKind::Immediate)->whereNotNull('sent_at')->count())->toBe(2);
});

it('does not send again when nothing changed', function () {
    findingAt($this->team, 'shop-api');

    app(SendFindingAlerts::class)->handle();
    app(SendFindingAlerts::class)->handle();

    expect(chatMessagesTo('COMMERCE'))->toHaveCount(1);
});

it('sends again when another service gets the same issue, marking it new', function () {
    findingAt($this->team, 'shop-api');
    app(SendFindingAlerts::class)->handle();

    findingAt($this->team, 'shop-web');
    app(SendFindingAlerts::class)->handle();

    $last = chatJson(chatMessagesTo('COMMERCE')->last());
    expect(chatMessagesTo('COMMERCE'))->toHaveCount(2)
        ->and($last)->toContain('2 service bị ảnh hưởng', 'shop-web</b> · mới', 'shop-api</b>"');
});

it('sends again when the severity rises', function () {
    $finding = findingAt($this->team, 'shop-api');
    app(SendFindingAlerts::class)->handle();

    $finding->update(['severity' => Severity::Critical]);
    app(SendFindingAlerts::class)->handle();

    expect(chatMessagesTo('COMMERCE'))->toHaveCount(2)
        ->and(chatMessagesTo('COMMERCE')->last()['text'])->toStartWith('[Critical]');
});

it('leaves findings below the immediate severities to the digest', function () {
    findingAt($this->team, 'shop-api', ['severity' => Severity::Medium]);

    expect(app(SendFindingAlerts::class)->handle())->toBe(0)
        ->and(chatMessagesTo('COMMERCE'))->toHaveCount(0);
});

it('follows the configured immediate severities', function () {
    config(['psm.alerts.immediate_severities' => ['critical']]);
    findingAt($this->team, 'shop-api');

    expect(app(SendFindingAlerts::class)->handle())->toBe(0);
});

it('skips resolved findings', function () {
    findingAt($this->team, 'shop-api', ['state' => 'resolved', 'resolved_at' => now()]);

    expect(app(SendFindingAlerts::class)->handle())->toBe(0);
});

it('keeps the alert of a team without a webhook and tells the admin space', function () {
    $team = Team::factory()->create(['name' => 'Booking', 'google_chat_webhook_url' => null]);
    findingAt($team, 'booking-api');

    app(SendFindingAlerts::class)->handle();

    $notification = $team->chatNotifications()->sole();
    expect($notification)
        ->kind->toBe(ChatNotificationKind::Immediate)
        ->failed_at->not->toBeNull()
        ->last_error->toBe('Team chưa có webhook.')
        ->and(chatMessagesTo('ADMIN'))->toHaveCount(1)
        ->and(chatJson(chatMessagesTo('ADMIN')->sole()))->toContain('Booking', 'Team chưa có webhook.');
});

it('writes the alert in the alert language even when an admin using another language triggers it', function () {
    app()->setLocale('ja');
    findingAt($this->team, 'shop-api');

    app(SendFindingAlerts::class)->handle();

    expect(chatJson(chatMessagesTo('COMMERCE')->sole()))->toContain('Mở service')->not->toContain('サービスを開く');
});
