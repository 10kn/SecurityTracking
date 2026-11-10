<?php

use App\Actions\AssignNoticeToIncident;
use App\Actions\SendDailyDigest;
use App\Enums\ChatNotificationKind;
use App\Enums\FindingEventType;
use App\Enums\IncidentAlertReason;
use App\Enums\RuntimeStatus;
use App\Enums\Severity;
use App\Enums\Technology;
use App\Models\Advisory;
use App\Models\ChatNotification;
use App\Models\Deployment;
use App\Models\Finding;
use App\Models\IncidentTeamNotice;
use App\Models\Lockfile;
use App\Models\Project;
use App\Models\Runtime;
use App\Models\RuntimeBranch;
use App\Models\RuntimeRelease;
use App\Models\Service;
use App\Models\Team;

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

it('lists new findings and counts the other open ones in the daily digest', function () {
    digestFinding($this->deployment, 'PHP 8.1 hết hỗ trợ');
    digestFinding($this->deployment, 'DEMO-GHSA-0001 trong guzzlehttp/guzzle', ['severity' => Severity::Medium], '2026-10-01 10:00:00');
    digestFinding($this->deployment, 'Node.js 20 hết hỗ trợ', ['state' => 'resolved', 'resolved_at' => '2026-10-23 12:00:00']);
    digestFinding($this->deployment, 'Ruby 3.1 hết hỗ trợ', ['state' => 'resolved', 'resolved_at' => '2026-10-20 12:00:00']);

    expect(app(SendDailyDigest::class)->handle())->toBe(1);

    $card = chatJson(chatMessagesTo('COMMERCE')->sole());
    expect($card)->toContain(
        'Bản tổng hợp bảo mật 24/10/2026', 'Mới: 1 · Đang mở: 2 · Đã tự đóng: 1', 'Finding mới', 'PHP 8.1 hết hỗ trợ',
        'Còn 1 finding khác đang mở (mức thấp hơn hoặc đã đọc), liệt kê trong bản tổng kết tuần.', 'Node.js 20 hết hỗ trợ',
    )->not->toContain('DEMO-GHSA-0001', 'Ruby 3.1');
});

it('sends no daily digest only for older findings below the immediate severities or for findings closed automatically', function () {
    digestFinding($this->deployment, 'DEMO-GHSA-0001 trong guzzlehttp/guzzle', ['severity' => Severity::Medium], '2026-10-01 10:00:00');
    digestFinding($this->deployment, 'Node.js 20 hết hỗ trợ', ['state' => 'resolved', 'resolved_at' => '2026-10-23 12:00:00']);

    expect(app(SendDailyDigest::class)->handle())->toBe(0);
});

it('reminds the team every day of its open critical and high findings', function () {
    digestFinding($this->deployment, 'PHP 8.1 hết hỗ trợ', [], '2026-10-01 10:00:00');

    app(SendDailyDigest::class)->handle();
    $this->travelTo('2026-10-25 01:00:00');
    app(SendDailyDigest::class)->handle();

    expect(chatMessagesTo('COMMERCE'))->toHaveCount(2)
        ->and(chatJson(chatMessagesTo('COMMERCE')->last()))->toContain('Cần xử lý, vẫn đang mở', '🟠 [High] PHP 8.1 hết hỗ trợ')->not->toContain('Finding mới');
});

it('stops reminding of a critical or high finding once an admin marked it as read', function () {
    digestFinding($this->deployment, 'PHP 8.1 hết hỗ trợ', ['acknowledged_at' => '2026-10-20 10:00:00', 'sla_due_at' => '2026-11-01 10:00:00'], '2026-10-01 10:00:00');

    expect(app(SendDailyDigest::class)->handle())->toBe(0);
});

it('sends no daily digest only for a new finding an admin already read', function () {
    digestFinding($this->deployment, 'PHP 8.1 hết hỗ trợ', ['acknowledged_at' => '2026-10-23 11:00:00', 'sla_due_at' => '2026-11-06 10:00:00']);

    expect(app(SendDailyDigest::class)->handle())->toBe(0);
});

it('reminds once when the deadline of a finding passes, read or not', function () {
    digestFinding($this->deployment, 'PHP 8.1 hết hỗ trợ', ['acknowledged_at' => '2026-10-20 10:00:00', 'sla_due_at' => '2026-10-23 12:00:00'], '2026-10-01 10:00:00');

    expect(app(SendDailyDigest::class)->handle())->toBe(1)
        ->and(chatJson(chatMessagesTo('COMMERCE')->sole()))->toContain('Quá hạn xử lý', '🟠 [High] PHP 8.1 hết hỗ trợ · quá hạn 23/10/2026 · đã đọc');

    $this->travelTo('2026-10-25 01:00:00');

    expect(app(SendDailyDigest::class)->handle())->toBe(0);
});

it('links to the unread findings of the team', function () {
    digestFinding($this->deployment, 'PHP 8.1 hết hỗ trợ', ['sla_due_at' => '2026-11-06 10:00:00']);

    app(SendDailyDigest::class)->handle();

    expect(chatJson(chatMessagesTo('COMMERCE')->sole()))->toContain('Xem và đánh dấu đã đọc', "/admin/findings?team={$this->team->id}&status=unread", 'PHP 8.1 hết hỗ trợ · hạn 06/11/2026');
});

it('counts from the last digest that was delivered, so a digest that failed loses nothing', function () {
    $this->team->chatNotifications()->create(['dedupe_key' => 'digest:old', 'kind' => ChatNotificationKind::Digest, 'payload' => [], 'sent_at' => '2026-10-22 01:00:00'])
        ->forceFill(['created_at' => '2026-10-22 01:00:00'])->save();
    $this->team->chatNotifications()->create(['dedupe_key' => 'digest:failed', 'kind' => ChatNotificationKind::Digest, 'payload' => [], 'failed_at' => '2026-10-23 02:00:00'])
        ->forceFill(['created_at' => '2026-10-23 01:00:00'])->save();
    digestFinding($this->deployment, 'DEMO-GHSA-0001 trong guzzlehttp/guzzle', ['severity' => Severity::Medium], '2026-10-22 18:00:00');

    expect(app(SendDailyDigest::class)->handle())->toBe(1)
        ->and(chatJson(chatMessagesTo('COMMERCE')->sole()))->toContain('Finding mới', 'DEMO-GHSA-0001');
});

it('still reports what came before a run of digests that all failed', function () {
    runtimeAt($this->team, 'shop-api', Technology::Php, '8.3.35');
    $this->team->chatNotifications()->create(['dedupe_key' => 'digest:failed-1', 'kind' => ChatNotificationKind::Digest, 'payload' => [], 'covers_until' => '2026-10-21 01:00:00', 'failed_at' => '2026-10-21 02:00:00']);
    $this->team->chatNotifications()->create(['dedupe_key' => 'digest:failed-2', 'kind' => ChatNotificationKind::Digest, 'payload' => [], 'covers_until' => '2026-10-23 01:00:00', 'failed_at' => '2026-10-23 02:00:00']);
    Advisory::factory()->preNotice(Technology::Php)->create(['title' => 'PHP security notice', 'severity' => Severity::Medium, 'published_at' => '2026-10-20 12:00:00', 'created_at' => '2026-10-20 12:00:00']);

    expect(app(SendDailyDigest::class)->handle())->toBe(1)
        ->and(chatJson(chatMessagesTo('COMMERCE')->sole()))->toContain('PHP security notice')
        ->and($this->team->chatNotifications()->latest('id')->first()->covers_until->toDateTimeString())->toBe('2026-10-24 01:00:00');
});

it('does not report the same unreadable lockfile again when only its commit changes', function () {
    $lockfile = Lockfile::factory()->for($this->deployment)->withContent('composer.lock', '{"packages": [')->create(['parsed_hash' => hash('sha256', '{"packages": ['), 'parse_error' => 'Not valid JSON: Syntax error.', 'uploaded_at' => '2026-10-23 12:00:00']);
    expect(app(SendDailyDigest::class)->handle())->toBe(1);

    $this->travelTo('2026-10-25 01:00:00');
    $lockfile->update(['commit_sha' => str_repeat('a', 40)]);

    expect(app(SendDailyDigest::class)->handle())->toBe(0);
});

it('says how many pre-notices it could not list', function () {
    config(['psm.alerts.max_listed_items' => 2]);
    runtimeAt($this->team, 'shop-api', Technology::Php, '8.3.35');
    Advisory::factory()->count(3)->preNotice(Technology::Php)->create(['severity' => Severity::Low]);

    app(SendDailyDigest::class)->handle();

    expect(chatJson(chatMessagesTo('COMMERCE')->sole()))->toContain('… và 1 mục khác');
});

it('counts end-of-life days in the digest timezone', function () {
    $php = syncedTechnology(Technology::Php);
    Runtime::factory()->for($this->deployment)->create(['technology' => $php, 'raw_version' => '8.4.26', 'branch' => '8.4', 'status' => RuntimeStatus::Ok, 'created_at' => '2026-09-01 00:00:00']);
    RuntimeBranch::where('technology', $php)->where('branch', '8.4')->update(['security_support_until' => '2026-11-27']);
    $this->team->chatNotifications()->create(['dedupe_key' => 'digest:previous', 'kind' => ChatNotificationKind::Digest, 'payload' => [], 'covers_until' => '2026-10-26 19:00:00', 'sent_at' => '2026-10-26 19:00:00']);

    $this->travelTo('2026-10-27 19:00:00');

    expect(app(SendDailyDigest::class)->handle())->toBe(1)
        ->and(chatJson(chatMessagesTo('COMMERCE')->sole()))->toContain('PHP 8.4.26 hết hỗ trợ từ 27/11/2026');
});

it('lists medium and low pre-notices of a technology the team runs', function () {
    runtimeAt($this->team, 'shop-api', Technology::Php, '8.3.35');
    Advisory::factory()->preNotice(Technology::Php)->create(['title' => 'PHP security notice', 'severity' => Severity::Medium, 'url' => 'https://www.php.net/archive/2026.php#notice']);
    Advisory::factory()->preNotice(Technology::Php)->create(['title' => 'Upcoming Critical PHP Security Release']);
    Advisory::factory()->preNotice(Technology::Ruby)->create(['title' => 'Ruby security notice', 'severity' => Severity::Low]);

    expect(app(SendDailyDigest::class)->handle())->toBe(1)
        ->and(chatJson(chatMessagesTo('COMMERCE')->sole()))->toContain('Tin bảo mật từ trang chính thức', '[Medium] PHP security notice', 'https://www.php.net/archive/2026.php#notice')
        ->not->toContain('Upcoming Critical', 'Ruby security notice');
});

it('lists a pre-notice the classifier rated medium only after the previous digest', function () {
    runtimeAt($this->team, 'shop-api', Technology::Php, '8.3.35');
    $preNotice = Advisory::factory()->preNotice(Technology::Php)->create(['title' => 'PHP security notice', 'severity' => Severity::Unknown, 'created_at' => '2026-10-23 00:59:00']);
    $this->team->chatNotifications()->create(['dedupe_key' => 'digest:previous', 'kind' => ChatNotificationKind::Digest, 'payload' => [], 'covers_until' => '2026-10-23 01:00:00', 'sent_at' => '2026-10-23 01:00:00']);
    $this->travelTo('2026-10-23 01:01:00');
    $preNotice->update(['severity' => Severity::Medium, 'classified_by' => 'claude-haiku-5-5']);

    $this->travelTo('2026-10-24 01:00:00');

    expect(app(SendDailyDigest::class)->handle())->toBe(1)
        ->and(chatJson(chatMessagesTo('COMMERCE')->sole()))->toContain('[Medium] PHP security notice');
});

it('reminds of an end of life 30, 7 and 1 days before, and once more when a reminder day was missed', function () {
    $php = syncedTechnology(Technology::Php);
    Runtime::factory()->for($this->deployment)->create(['technology' => $php, 'raw_version' => '8.4.26', 'branch' => '8.4', 'status' => RuntimeStatus::Ok, 'created_at' => '2026-09-01 00:00:00']);
    RuntimeBranch::where('technology', $php)->where('branch', '8.4')->update(['security_support_until' => '2026-11-27']);
    $digestOn = function (string $date): int {
        $this->travelTo("{$date} 01:00:00");

        return app(SendDailyDigest::class)->handle();
    };

    expect($digestOn('2026-10-27'))->toBe(0)
        ->and($digestOn('2026-10-28'))->toBe(1)
        ->and(chatJson(chatMessagesTo('COMMERCE')->last()))->toContain('Sắp hết hỗ trợ', 'PHP 8.4.26 hết hỗ trợ từ 27/11/2026')
        ->and($digestOn('2026-10-29'))->toBe(0)
        ->and($digestOn('2026-11-20'))->toBe(1)
        ->and($digestOn('2026-11-26'))->toBe(1);
});

it('sends the weekly summary on Monday instead of the daily digest, with everything still open', function () {
    $this->travelTo('2026-10-26 01:00:00');
    digestFinding($this->deployment, 'DEMO-GHSA-0001 trong guzzlehttp/guzzle', ['severity' => Severity::Medium], '2026-10-01 10:00:00');
    digestFinding($this->deployment, 'Node.js 20 hết hỗ trợ', ['state' => 'resolved', 'resolved_at' => '2026-10-21 12:00:00']);
    digestFinding($this->deployment, 'Ruby 3.1 hết hỗ trợ', ['state' => 'resolved', 'resolved_at' => '2026-10-18 12:00:00']);

    expect(app(SendDailyDigest::class)->handle())->toBe(1)
        ->and(chatJson(chatMessagesTo('COMMERCE')->sole()))->toContain('Tổng kết tuần bảo mật 26/10/2026', 'Vẫn đang mở', '[Medium] DEMO-GHSA-0001', 'Node.js 20 hết hỗ trợ')
        ->not->toContain('Ruby 3.1', 'liệt kê trong bản tổng kết tuần')
        ->and($this->team->chatNotifications()->sole()->dedupe_key)->toBe("digest-weekly:{$this->team->id}:2026-W44");
});

it('sends the weekly summary to a team whose only open finding is new and already read', function () {
    $this->travelTo('2026-10-26 01:00:00');
    digestFinding($this->deployment, 'PHP 8.1 hết hỗ trợ', ['acknowledged_at' => '2026-10-25 11:00:00', 'sla_due_at' => '2026-11-08 10:00:00'], '2026-10-25 10:00:00');

    expect(app(SendDailyDigest::class)->handle())->toBe(1)
        ->and(chatJson(chatMessagesTo('COMMERCE')->sole()))->toContain('Tổng kết tuần', 'PHP 8.1 hết hỗ trợ · hạn 08/11/2026 · đã đọc');
});

it('sends no weekly summary to a team with nothing at all', function () {
    $this->travelTo('2026-10-26 01:00:00');

    expect(app(SendDailyDigest::class)->handle())->toBe(0);
});

it('counts runtimes with missing data or a newer release, and lists those reaching end of life soon', function () {
    $php = syncedTechnology(Technology::Php);
    Runtime::factory()->for($this->deployment)->create(['technology' => Technology::Ruby, 'status' => RuntimeStatus::Unknown]);
    Runtime::factory()->for($this->deployment)->create(['technology' => $php, 'raw_version' => '8.4.26', 'branch' => '8.4', 'status' => RuntimeStatus::Outdated]);
    RuntimeBranch::where('technology', $php)->where('branch', '8.4')->update(['security_support_until' => '2026-11-15']);

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

it('does not count an unreadable lockfile that another lockfile in its directory replaces', function () {
    Lockfile::factory()->for($this->deployment)->withContent('package-lock.json', 'not json')->create(['parsed_hash' => hash('sha256', 'not json'), 'parse_error' => 'Not valid JSON: Syntax error.']);
    Lockfile::factory()->for($this->deployment)->withContent('yarn.lock', "lockfileVersion: 1\n")->create(['parsed_hash' => hash('sha256', "lockfileVersion: 1\n"), 'parse_error' => 'Not a yarn.lock.']);
    Lockfile::factory()->for($this->deployment)->withContent('web/package-lock.json', 'not json')->create(['parsed_hash' => hash('sha256', 'not json'), 'parse_error' => 'Not valid JSON: Syntax error.']);

    app(SendDailyDigest::class)->handle();

    expect(chatJson(chatMessagesTo('COMMERCE')->sole()))->toContain('Lockfile không đọc được: 2');
});

it('sends no digest only because a service has no lockfile', function () {
    expect(app(SendDailyDigest::class)->handle())->toBe(0);
});

it('lists an incident by its most severe pre-notice, and not one sent right away or already in a card', function () {
    runtimeAt($this->team, 'shop-api', Technology::Php, '8.3.35');
    Advisory::factory()->preNotice(Technology::Php)->create(['title' => 'PHP low notice', 'severity' => Severity::Low, 'aliases' => ['CVE-2026-1001']]);
    Advisory::factory()->preNotice(Technology::Php)->create(['title' => 'PHP medium notice', 'severity' => Severity::Medium, 'aliases' => ['CVE-2026-1001']]);
    Advisory::factory()->preNotice(Technology::Php)->create(['title' => 'PHP medium part of a critical issue', 'severity' => Severity::Medium, 'aliases' => ['CVE-2026-2002']]);
    Advisory::factory()->preNotice(Technology::Php)->create(['title' => 'Upcoming Critical PHP Security Release', 'aliases' => ['CVE-2026-2002']]);
    $told = Advisory::factory()->preNotice(Technology::Php)->create(['title' => 'PHP medium notice the team had in a card', 'severity' => Severity::Medium]);
    app(AssignNoticeToIncident::class)->assignUnassigned();
    IncidentTeamNotice::create(['team_id' => $this->team->id, 'advisory_id' => $told->id, 'severity' => Severity::Medium, 'reason' => IncidentAlertReason::New]);

    expect(app(SendDailyDigest::class)->handle())->toBe(1);

    $card = chatJson(chatMessagesTo('COMMERCE')->sole());
    expect($card)->toContain('[Medium] PHP medium notice')
        ->not->toContain('PHP low notice', 'part of a critical issue', 'Upcoming Critical', 'had in a card');
});

/**
 * A critical php incident the team got a card about on 2026-10-23, with a service on 8.3, that an admin marked as read
 * for the team as affecting it, so it is listed as waiting for a fix rather than reminded of.
 */
function incidentAwaitingPatch(Team $team, Deployment $deployment): Advisory
{
    Runtime::factory()->for($deployment)->create(['technology' => Technology::Php, 'raw_version' => '8.3.35', 'version' => '8.3.35', 'branch' => '8.3', 'status' => RuntimeStatus::Ok]);
    $notice = Advisory::factory()->preNotice(Technology::Php)->create(['title' => 'Upcoming Critical PHP Security Release', 'published_at' => '2026-10-20 20:32:00']);
    app(AssignNoticeToIncident::class)->handle($notice);
    IncidentTeamNotice::create(['team_id' => $team->id, 'advisory_id' => $notice->id, 'severity' => Severity::Critical, 'reason' => IncidentAlertReason::New])
        ->forceFill(['created_at' => '2026-10-23 10:00:00'])->save();
    assessIncident($notice->refresh()->incident, $team);

    return $notice->refresh();
}

it('lists the incidents waiting for a fix with the security releases that may be it, and sends no digest for that list alone', function () {
    incidentAwaitingPatch($this->team, $this->deployment);
    RuntimeRelease::factory()->security()->create(['branch' => '8.3', 'version' => '8.3.36', 'released_at' => '2026-10-21', 'created_at' => '2026-10-23 15:00:00']);

    expect(app(SendDailyDigest::class)->handle())->toBe(1)
        ->and(chatJson(chatMessagesTo('COMMERCE')->sole()))->toContain('Đang chờ bản vá', '🔴 [Critical] Upcoming Critical PHP Security Release', 'Nhánh 8.3 · có thể là bản vá: 8.3.36 (trang chính thức PHP chưa xác nhận)');

    $this->travelTo('2026-10-25 01:00:00');

    expect(app(SendDailyDigest::class)->handle())->toBe(0);
});

it('asks for a review once, when an incident is still waiting for a fix after the configured days', function () {
    incidentAwaitingPatch($this->team, $this->deployment);
    app(SendDailyDigest::class)->handle();

    $this->travelTo('2026-11-04 01:00:00');

    expect(app(SendDailyDigest::class)->handle())->toBe(1)
        ->and(chatJson(chatMessagesTo('COMMERCE')->last()))->toContain('cần rà soát: quá 14 ngày chưa có bản vá');

    $this->travelTo('2026-11-05 01:00:00');

    expect(app(SendDailyDigest::class)->handle())->toBe(0);
});

it('no longer lists an incident once the vendor confirmed the fix for the branch', function () {
    $notice = incidentAwaitingPatch($this->team, $this->deployment);
    $notice->update(['patch_versions' => ['8.3.36']]);
    RuntimeRelease::factory()->security()->create(['branch' => '8.3', 'version' => '8.3.36', 'released_at' => '2026-10-21']);

    app(SendDailyDigest::class)->handle();

    expect(chatJson(chatMessagesTo('COMMERCE')))->not->toContain('Đang chờ bản vá');
});

it('counts a card from before incidents as telling the team, from when it was queued', function (string $queuedAt, int $digests) {
    Runtime::factory()->for($this->deployment)->create(['technology' => Technology::Php, 'raw_version' => '8.3.35', 'version' => '8.3.35', 'branch' => '8.3', 'status' => RuntimeStatus::Ok]);
    $notice = Advisory::factory()->preNotice(Technology::Php)->create(['title' => 'Upcoming Critical PHP Security Release', 'published_at' => '2026-10-20 20:32:00']);
    app(AssignNoticeToIncident::class)->handle($notice);
    ChatNotification::factory()->for($this->team)->sent()->create(['kind' => ChatNotificationKind::PreNotice, 'dedupe_key' => "pre_notice:{$notice->id}:{$this->team->id}:critical"])
        ->forceFill(['created_at' => $queuedAt])->save();
    assessIncident($notice->refresh()->incident, $this->team);

    expect(app(SendDailyDigest::class)->handle())->toBe($digests);

    $this->travelTo('2026-10-26 01:00:00');
    digestFinding($this->deployment, 'PHP 8.1 hết hỗ trợ', [], '2026-10-25 10:00:00');
    app(SendDailyDigest::class)->handle();

    expect(chatJson(chatMessagesTo('COMMERCE')->last()))->toContain('Đang chờ bản vá', '🔴 [Critical] Upcoming Critical PHP Security Release');
})->with([
    'queued since the previous digest' => ['2026-10-23 10:00:00', 1],
    'queued before it' => ['2026-10-21 10:00:00', 0],
]);
