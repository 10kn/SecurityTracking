<?php

use App\Actions\SendFindingAlerts;
use App\Enums\ChatNotificationKind;
use App\Enums\FindingRule;
use App\Enums\IncidentAlertReason;
use App\Enums\Severity;
use App\Enums\Technology;
use App\Models\Advisory;
use App\Models\ChatNotification;
use App\Models\Deployment;
use App\Models\Finding;
use App\Models\IncidentTeamNotice;
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

it('links to the findings of the card to mark them as read, and gives the deadline', function () {
    $first = findingAt($this->team, 'shop-api', ['sla_due_at' => '2026-11-06 10:00:00']);
    $second = findingAt($this->team, 'shop-web', ['sla_due_at' => '2026-11-06 10:00:00']);

    app(SendFindingAlerts::class)->handle();

    expect(chatJson(chatMessagesTo('COMMERCE')->sole()))->toContain(
        'Nhắc lại trong bản tổng hợp mỗi ngày cho tới khi admin đánh dấu đã đọc. Hạn xử lý: 06/11/2026.',
        'Đánh dấu đã đọc',
        "/admin/findings?team={$this->team->id}&ids={$first->id}%2C{$second->id}",
    );
});

/**
 * A "thiếu bản vá" finding of a php runtime on 8.3.
 */
function outdatedSecurityFinding(Team $team, array $attributes = []): Finding
{
    $runtime = runtimeAt($team, 'shop-api', Technology::Php, '8.3.35');
    $runtime->update(['version' => '8.3.35', 'branch' => '8.3']);

    return Finding::factory()->for($runtime->deployment)->create([
        'rule' => FindingRule::RuntimeOutdatedSecurity,
        'runtime_id' => $runtime->id,
        'group_key' => 'runtime_outdated_security:php:8.3.36',
        'context' => ['latest' => '8.3.36'],
        ...$attributes,
    ]);
}

it('holds a new "thiếu bản vá" finding for a few hours while the team waits for the fix of an incident it was warned of', function () {
    $this->travelTo('2026-10-21 15:00:00');
    $notice = Advisory::factory()->preNotice(Technology::Php)->create(['published_at' => now()->subDay()]);
    IncidentTeamNotice::create(['team_id' => $this->team->id, 'advisory_id' => $notice->id, 'severity' => Severity::Critical, 'reason' => IncidentAlertReason::New]);
    outdatedSecurityFinding($this->team);

    expect(app(SendFindingAlerts::class)->handle())->toBe(0);

    $this->travel(361)->minutes();

    expect(app(SendFindingAlerts::class)->handle())->toBe(1);
});

it('holds the finding as well when the team was warned by a card from before incidents', function () {
    $this->travelTo('2026-10-21 15:00:00');
    $notice = Advisory::factory()->preNotice(Technology::Php)->create(['published_at' => now()->subDay()]);
    ChatNotification::factory()->for($this->team)->sent()->create(['kind' => ChatNotificationKind::PreNotice, 'dedupe_key' => "pre_notice:{$notice->id}:{$this->team->id}:critical"]);
    outdatedSecurityFinding($this->team);

    expect(app(SendFindingAlerts::class)->handle())->toBe(0);

    $this->travel(361)->minutes();

    expect(app(SendFindingAlerts::class)->handle())->toBe(1);
});

it('does not hold the finding of a team that was not warned of an incident of the technology', function () {
    Advisory::factory()->preNotice(Technology::Php)->create();
    outdatedSecurityFinding($this->team);

    expect(app(SendFindingAlerts::class)->handle())->toBe(1);
});

it('counts a finding a "Đã có bản vá" card stands for as sent, without a card of its own', function () {
    $finding = outdatedSecurityFinding($this->team, ['severity' => Severity::Critical, 'context' => ['latest' => '8.3.36', 'covered_by_patch_id' => 1]]);

    expect(app(SendFindingAlerts::class)->handle())->toBe(0)
        ->and(chatMessagesTo('COMMERCE'))->toHaveCount(0)
        ->and($finding->refresh()->notified_severity)->toBe(Severity::Critical);
});

it('does not mark as sent a covered finding that lost its card after it was read', function () {
    $finding = outdatedSecurityFinding($this->team, ['severity' => Severity::Critical, 'context' => ['latest' => '8.3.36', 'covered_by_patch_id' => 1]]);
    $isChanged = false;

    Finding::retrieved(function (Finding $read) use (&$isChanged): void {
        if (! $isChanged) {
            $isChanged = true;
            Finding::whereKey($read->id)->update(['severity' => Severity::High, 'context' => ['latest' => '8.3.40'], 'notified_severity' => null, 'notified_at' => null]);
        }
    });

    app(SendFindingAlerts::class)->handle();
    Finding::flushEventListeners();

    expect($finding->refresh()->notified_severity)->toBeNull()
        ->and($finding->severity)->toBe(Severity::High);
});
