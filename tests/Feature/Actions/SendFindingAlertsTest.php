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

/**
 * Vulnerable packages of `$service`, one finding per advisory of `$advisories` on its production deployment.
 *
 * @param  list<string>  $advisories
 * @return list<Finding>
 */
function packageFindingsAt(Team $team, string $service, array $advisories, array $attributes = []): array
{
    $deployment = Deployment::whereHas('service', fn ($query) => $query->where('name', $service))->first()
        ?? Deployment::factory()->for(Service::factory()->for(Project::factory()->for($team)->state(['name' => 'Shop']))->state(['name' => $service]))->create();

    return array_map(fn (string $advisory): Finding => Finding::factory()->for($deployment)->create([
        'rule' => FindingRule::DependencyVulnerable,
        'group_key' => "dependency_vulnerable:{$advisory}",
        'title' => "{$advisory} trong lodash",
        'context' => ['package' => 'lodash', 'advisory' => $advisory, 'detail' => 'lodash 4.17.20 · nâng lên 4.17.21 · package-lock.json'],
        ...$attributes,
    ]), $advisories);
}

it('sends one short card for a service with many vulnerable packages, with how many and the highest severity', function () {
    packageFindingsAt($this->team, 'shop-web', ['GHSA-1', 'GHSA-2', 'GHSA-3']);
    $critical = packageFindingsAt($this->team, 'shop-web', ['GHSA-4'], ['severity' => Severity::Critical])[0];
    $service = $critical->deployment->service;

    expect(app(SendFindingAlerts::class)->handle())->toBe(1);

    $message = chatMessagesTo('COMMERCE')->sole();
    expect($message['text'])->toBe('[Critical] shop-web: phát hiện 4 lỗ hổng trong lockfile')
        ->and(chatJson($message))->toContain('Project Shop. Mức cao nhất: Critical.', 'Xem findings', "/admin/findings?service={$service->id}")
        ->not->toContain('GHSA-1', 'lodash 4.17.20')
        ->and(Finding::whereNull('notified_at')->count())->toBe(0);

    expect(app(SendFindingAlerts::class)->handle())->toBe(0);
});

it('keeps one card per advisory below the threshold, counting advisories rather than environments', function () {
    $finding = packageFindingsAt($this->team, 'shop-web', ['GHSA-1'])[0];
    foreach (['staging', 'development'] as $environment) {
        $deployment = $finding->deployment->service->deployments()->create(['environment' => $environment]);
        Finding::factory()->for($deployment)->create(['rule' => FindingRule::DependencyVulnerable, 'group_key' => 'dependency_vulnerable:GHSA-1', 'title' => 'GHSA-1 trong lodash']);
    }
    packageFindingsAt($this->team, 'shop-web', ['GHSA-2']);

    expect(app(SendFindingAlerts::class)->handle())->toBe(2)
        ->and(chatMessagesTo('COMMERCE')->pluck('text')->all())->toContain('[High] GHSA-1 trong lodash', '[High] GHSA-2 trong lodash');
});

it('says how many findings the advisories are when the service has several environments', function () {
    $findings = packageFindingsAt($this->team, 'shop-web', ['GHSA-1', 'GHSA-2', 'GHSA-3']);
    $staging = $findings[0]->deployment->service->deployments()->create(['environment' => 'staging']);
    Finding::factory()->for($staging)->create(['rule' => FindingRule::DependencyVulnerable, 'group_key' => 'dependency_vulnerable:GHSA-1']);

    app(SendFindingAlerts::class)->handle();

    expect(chatJson(chatMessagesTo('COMMERCE')->sole()))->toContain('phát hiện 3 lỗ hổng trong lockfile', 'Gồm 4 finding trên các môi trường của service.');
});

it('sends the CISA KEV packages of a summarized service on their own card, listing every service exploited the same way', function () {
    packageFindingsAt($this->team, 'shop-web', ['GHSA-1', 'GHSA-2', 'GHSA-3']);
    $knownExploited = ['known_exploited' => [['cve' => 'CVE-2026-4242', 'name' => 'lodash prototype pollution', 'date_added' => '2026-11-01', 'due_date' => '2026-11-22', 'listed' => true]]];
    packageFindingsAt($this->team, 'shop-web', ['GHSA-KEV'], ['severity' => Severity::Critical, 'context' => $knownExploited]);
    packageFindingsAt($this->team, 'shop-api', ['GHSA-KEV'], ['severity' => Severity::Critical, 'context' => $knownExploited]);

    expect(app(SendFindingAlerts::class)->handle())->toBe(2);

    [$summary, $exploited] = chatMessagesTo('COMMERCE')->all();
    expect($summary['text'])->toBe('[High] shop-web: phát hiện 3 lỗ hổng trong lockfile')
        ->and(chatJson($summary))->toContain('Ngoài ra 1 lỗ hổng có trong danh sách CISA KEV được cảnh báo riêng.')
        ->and($exploited['text'])->toBe('[Critical] GHSA-KEV trong lodash')
        ->and(chatJson($exploited))->toContain('2 service bị ảnh hưởng', 'shop-web', 'shop-api', 'CVE-2026-4242');
});

it('leaves a summarized service out of what is new on the card of an issue another service shares', function () {
    packageFindingsAt($this->team, 'shop-web', ['GHSA-1', 'GHSA-2', 'GHSA-3']);
    packageFindingsAt($this->team, 'shop-api', ['GHSA-1']);
    packageFindingsAt($this->team, 'shop-admin', ['GHSA-1'], ['notified_severity' => Severity::High, 'notified_at' => now()]);

    expect(app(SendFindingAlerts::class)->handle())->toBe(2);

    $issue = chatJson(chatMessagesTo('COMMERCE')->last());
    expect($issue)->toContain('3 service bị ảnh hưởng', 'shop-api</b> · mới', 'shop-web</b>"')
        ->and(Finding::whereNull('notified_at')->count())->toBe(0);
});

it('sends one card per advisory when the summary is turned off', function () {
    config(['psm.alerts.lockfile_summary_threshold' => 0]);
    packageFindingsAt($this->team, 'shop-web', ['GHSA-1', 'GHSA-2', 'GHSA-3']);

    expect(app(SendFindingAlerts::class)->handle())->toBe(3);
});
