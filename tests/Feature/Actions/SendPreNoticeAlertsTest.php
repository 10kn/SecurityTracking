<?php

use App\Actions\AssignNoticeToIncident;
use App\Actions\EvaluateRuntimes;
use App\Actions\SendFindingAlerts;
use App\Actions\SendPreNoticeAlerts;
use App\Actions\SyncFindings;
use App\Enums\ChatNotificationKind;
use App\Enums\FindingRule;
use App\Enums\RuntimeStatus;
use App\Enums\Severity;
use App\Enums\SeverityBasis;
use App\Enums\Technology;
use App\Models\Advisory;
use App\Models\ChatNotification;
use App\Models\Finding;
use App\Models\Incident;
use App\Models\IncidentTeamNotice;
use App\Models\IncidentTeamPatch;
use App\Models\Runtime;
use App\Models\RuntimeRelease;
use App\Models\Team;
use App\Services\RuntimeFindings;
use Illuminate\Support\Facades\DB;

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
    expect($card['text'])->toBe('🔴 [Critical] Upcoming Critical PHP Security Release')
        ->and(chatJson($card))->toContain(
            'Cảnh báo chuẩn bị · Commerce',
            'It addresses two critical vulnerabilities.',
            'Nguồn: php.net · Đăng lúc 21/10/2026 03:32 GMT+7 · CVSS 9.2',
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
        ->toContain('PHP sẽ vá hai lỗ hổng nghiêm trọng.', 'Dự kiến phát hành 21/10/2026 21:00 GMT+7 · Nhánh bị ảnh hưởng: 8.3, 8.4')
        ->not->toContain('It addresses two critical vulnerabilities.');
});

it('shows times in the timezone of the alert locale', function () {
    config(['psm.alerts.locale' => 'ja']);
    runtimeAt($this->commerce, 'shop-api', $this->php, '8.3.35');
    $this->preNotice->update(['planned_release_at' => '2026-10-21 14:00:00']);

    app(SendPreNoticeAlerts::class)->handle();

    expect(chatJson(chatMessagesTo('COMMERCE')->sole()))->toContain('公開日時 2026/10/21 05:32 JST', 'リリース予定 2026/10/21 23:00 JST');
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
        ->and($messages->last()['text'])->toBe('🔴 [Critical] Upcoming Critical PHP Security Release')
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

it('holds pre-notices the classifier has not read yet, unless their CVSS score makes them critical, when asked', function () {
    runtimeAt($this->commerce, 'shop-api', $this->php, '8.3.35');
    $this->preNotice->update(['severity_basis' => SeverityBasis::Vendor]);
    $high = Advisory::factory()->preNotice($this->php)->create(['severity' => Severity::High, 'published_at' => now()->subHour()]);
    $criticalInWords = Advisory::factory()->preNotice($this->php)->create(['title' => 'Critical PHP security release', 'severity_basis' => SeverityBasis::Vendor, 'published_at' => now()->subHour()]);

    expect(app(SendPreNoticeAlerts::class)->handle(holdUnclassified: true))->toBe(1)
        ->and(chatMessagesTo('COMMERCE')->sole()['text'])->toBe('🔴 [Critical] Upcoming Critical PHP Security Release');

    $high->update(['classified_by' => 'claude-haiku-5-5']);

    expect(app(SendPreNoticeAlerts::class)->handle(holdUnclassified: true))->toBe(1);

    $criticalInWords->update(['classified_by' => 'claude-haiku-5-5']);

    expect(app(SendPreNoticeAlerts::class)->handle(holdUnclassified: true))->toBe(1);
});

it('sends a pre-notice a KEV listing makes critical without waiting for the classifier', function () {
    runtimeAt($this->commerce, 'shop-api', $this->php, '8.3.35');
    $this->preNotice->update(['cvss_score' => null, 'severity_basis' => SeverityBasis::Vendor, 'known_exploited' => [['cve' => 'CVE-2026-109618', 'name' => 'PHP RCE', 'date_added' => '2026-10-20', 'due_date' => null, 'listed' => true]]]);

    expect(app(SendPreNoticeAlerts::class)->handle(holdUnclassified: true))->toBe(1);
});

it('holds a pre-notice only the vendor\'s words make critical until the classifier reads it', function () {
    runtimeAt($this->commerce, 'shop-api', $this->php, '8.3.35');
    $this->preNotice->update(['cvss_score' => null, 'severity_basis' => SeverityBasis::Vendor]);

    expect(app(SendPreNoticeAlerts::class)->handle(holdUnclassified: true))->toBe(0)
        ->and(app(SendPreNoticeAlerts::class)->handle())->toBe(1);
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

    $reply = chatMessagesTo('COMMERCE')->last();
    expect($reply['text'])->toBe('🔴 [Critical] PHP security advisory, now critical')
        ->and($reply['thread']['threadKey'])->toBe(chatMessagesTo('COMMERCE')->first()['thread']['threadKey'])
        ->and(chatJson($reply))->toContain('Cập nhật · Commerce', 'Mức độ tăng từ High lên Critical.', 'shop-api')->not->toContain('Tin khác của cùng sự kiện');
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

/**
 * A runtime of the team's service on a known branch.
 */
function runtimeOnBranch(Team $team, string $service, string $version): Runtime
{
    $runtime = runtimeAt($team, $service, Technology::Php, $version);
    $runtime->update(['version' => $version, 'branch' => implode('.', array_slice(explode('.', $version), 0, 2))]);

    return $runtime;
}

it('sends one "Đã có bản vá" card once the vendor names the version and the catalog lists it as a security release', function () {
    $this->travelTo('2026-10-21 15:00:00');
    runtimeOnBranch($this->commerce, 'shop-api', '8.3.35');
    runtimeOnBranch($this->commerce, 'shop-legacy', '8.1.31');
    runtimeOnBranch($this->booking, 'booking-api', '8.3.36');
    $this->preNotice->update(['patch_versions' => ['8.5.12', '8.4.27', '8.3.36']]);
    RuntimeRelease::factory()->security()->create(['branch' => '8.3', 'version' => '8.3.36']);

    expect(app(SendPreNoticeAlerts::class)->handle())->toBe(2);

    $card = chatMessagesTo('COMMERCE')->sole();
    expect($card['text'])->toBe('🔴 [Critical] Đã có bản vá, nâng lên 8.3.36')
        ->and(chatJson($card))->toContain(
            'Hãng đã xác nhận bản vá 8.3.36. Có bản vá không có nghĩa service đã an toàn: chỉ an toàn sau khi nâng cấp.',
            'Hạn xử lý: 24/10/2026.',
            '1 service cần nâng cấp', 'PHP 8.3.35 → 8.3.36',
            '1 service ở nhánh chưa có bản vá', 'PHP 8.1.31 · Phạm vi: chưa xác định',
            'Upcoming Critical PHP Security Release',
        )
        ->and(chatMessagesTo('BOOKING')->sole()['text'])->toBe('🔴 [Critical] Upcoming Critical PHP Security Release')
        ->and(IncidentTeamPatch::sole())->team_id->toBe($this->commerce->id)->branch->toBe('8.3')->version->toBe('8.3.36')->severity->toBe(Severity::Critical);

    $this->travel(15)->minutes();

    expect(app(SendPreNoticeAlerts::class)->handle())->toBe(0);
});

it('does not confirm a fix the catalog does not list as a security release yet, nor a release no pre-notice names', function (?bool $isSecurityRelease, array $patchVersions) {
    runtimeOnBranch($this->commerce, 'shop-api', '8.3.35');
    $this->preNotice->update(['patch_versions' => $patchVersions]);

    if ($isSecurityRelease !== null) {
        RuntimeRelease::factory()->create(['branch' => '8.3', 'version' => '8.3.36', 'is_security_release' => $isSecurityRelease]);
    }

    app(SendPreNoticeAlerts::class)->handle();

    expect(chatMessagesTo('COMMERCE')->sole()['text'])->toBe('🔴 [Critical] Upcoming Critical PHP Security Release')
        ->and(IncidentTeamPatch::count())->toBe(0);
})->with([
    'not in the catalog yet' => [null, ['8.3.36']],
    'not tagged security' => [false, ['8.3.36']],
    'named by no pre-notice' => [true, []],
]);

it('sends the fix after the first card once the catalog lists it, and then the team\'s "thiếu bản vá" finding sends no card of its own', function () {
    runtimeOnBranch($this->commerce, 'shop-api', '8.3.35');
    $this->preNotice->update(['patch_versions' => ['8.3.36']]);
    app(SendPreNoticeAlerts::class)->handle();

    $this->travel(2)->hours();
    RuntimeRelease::factory()->security()->create(['branch' => '8.3', 'version' => '8.3.36']);
    $finding = Finding::factory()->for(Runtime::sole()->deployment)->create([
        'rule' => FindingRule::RuntimeOutdatedSecurity,
        'runtime_id' => Runtime::sole()->id,
        'severity' => Severity::High,
        'group_key' => 'runtime_outdated_security:php:8.3.36',
        'context' => ['latest' => '8.3.36'],
        'sla_due_at' => now()->addDays(14),
    ]);

    expect(app(SendPreNoticeAlerts::class)->handle())->toBe(1)
        ->and(chatMessagesTo('COMMERCE')->pluck('text')->all())->toBe(['🔴 [Critical] Upcoming Critical PHP Security Release', '🔴 [Critical] Đã có bản vá, nâng lên 8.3.36'])
        ->and($finding->refresh())
        ->severity->toBe(Severity::Critical)
        ->notified_severity->toBe(Severity::Critical)
        ->sla_due_at->toDateTimeString()->toBe(now()->addDays(3)->toDateTimeString())
        ->and($finding->context['covered_by_patch_id'])->toBe(IncidentTeamPatch::sole()->id)
        ->and(app(SendFindingAlerts::class)->handle())->toBe(0);
});

/**
 * An open "thiếu bản vá" finding of the runtime asking for `$latest`.
 */
function patchableFinding(Runtime $runtime, string $latest, array $attributes = []): Finding
{
    return Finding::factory()->for($runtime->deployment)->create([
        'rule' => FindingRule::RuntimeOutdatedSecurity,
        'runtime_id' => $runtime->id,
        'severity' => Severity::High,
        'group_key' => "runtime_outdated_security:php:{$latest}",
        'context' => ['latest' => $latest],
        'sla_due_at' => now()->addDays(14),
        ...$attributes,
    ]);
}

it('leaves a finding asking for a later release than the card\'s alone, so it sends its own card', function () {
    $runtime = runtimeOnBranch($this->commerce, 'shop-api', '8.3.35');
    $this->preNotice->update(['patch_versions' => ['8.3.36']]);
    RuntimeRelease::factory()->security()->create(['branch' => '8.3', 'version' => '8.3.36']);
    $finding = patchableFinding($runtime, '8.3.40');

    app(SendPreNoticeAlerts::class)->handle();

    expect($finding->refresh())->severity->toBe(Severity::High)->notified_severity->toBeNull()
        ->and($finding->context)->not->toHaveKey('covered_by_patch_id');
});

it('does not cover the finding once the catalog has a later security release than the card\'s', function () {
    $runtime = runtimeOnBranch($this->commerce, 'shop-api', '8.3.35');
    $this->preNotice->update(['patch_versions' => ['8.3.36']]);
    RuntimeRelease::factory()->security()->create(['branch' => '8.3', 'version' => '8.3.36']);
    app(SendPreNoticeAlerts::class)->handle();

    $this->travel(1)->day();
    RuntimeRelease::factory()->security()->create(['branch' => '8.3', 'version' => '8.3.40']);
    $runtime->update(['status' => RuntimeStatus::OutdatedSecurity, 'status_context' => ['latest' => '8.3.40']]);
    app(EvaluateRuntimes::class)->syncRuntimeFindings([$runtime->deployment_id]);

    $finding = Finding::sole();
    expect($finding)->severity->toBe(Severity::High)
        ->and($finding->context)->not->toHaveKey('covered_by_patch_id');

    $this->travel(7)->hours();

    expect(app(SendFindingAlerts::class)->handle())->toBe(1)
        ->and(chatJson(chatMessagesTo('COMMERCE')->last()))->toContain('PHP 8.3 thiếu bản vá bảo mật, cần nâng lên 8.3.40');
});

it('sends another card when the vendor revises the fixed version up in the same pre-notice, but not when it revises it down', function () {
    runtimeOnBranch($this->commerce, 'shop-api', '8.3.35');
    RuntimeRelease::factory()->security()->create(['branch' => '8.3', 'version' => '8.3.36']);
    RuntimeRelease::factory()->security()->create(['branch' => '8.3', 'version' => '8.3.37']);
    $this->preNotice->update(['patch_versions' => ['8.3.36']]);
    app(SendPreNoticeAlerts::class)->handle();

    $this->travel(1)->hour();
    $this->preNotice->update(['patch_versions' => ['8.3.37']]);

    expect(app(SendPreNoticeAlerts::class)->handle())->toBe(1)
        ->and(chatMessagesTo('COMMERCE')->pluck('text')->all())->toBe(['🔴 [Critical] Đã có bản vá, nâng lên 8.3.36', '🔴 [Critical] Đã có bản vá, nâng lên 8.3.37'])
        ->and(IncidentTeamPatch::orderBy('id')->pluck('version')->all())->toBe(['8.3.36', '8.3.37']);

    $this->travel(1)->hour();
    $this->preNotice->update(['patch_versions' => ['8.3.36']]);

    expect(app(SendPreNoticeAlerts::class)->handle())->toBe(0)
        ->and(IncidentTeamPatch::count())->toBe(2);
});

it('still sends the fix to a team told about the incident once its pre-notices are older than the configured days, however long ago it began', function (bool $isLegacyCard) {
    runtimeOnBranch($this->commerce, 'shop-api', '8.3.35');

    if ($isLegacyCard) {
        ChatNotification::factory()->for($this->commerce)->sent()->create([
            'kind' => ChatNotificationKind::PreNotice,
            'dedupe_key' => "pre_notice:{$this->preNotice->id}:{$this->commerce->id}:critical",
            'payload' => ['text' => '[Critical] Upcoming Critical PHP Security Release'],
        ]);
    } else {
        app(SendPreNoticeAlerts::class)->handle();
    }

    $this->travelTo('2026-11-09 21:00:00');
    runtimeOnBranch($this->booking, 'booking-api', '8.3.35');
    $this->preNotice->update(['patch_versions' => ['8.3.36']]);
    RuntimeRelease::factory()->security()->create(['branch' => '8.3', 'version' => '8.3.36']);

    expect(app(SendPreNoticeAlerts::class)->handle())->toBe(1)
        ->and(chatMessagesTo('COMMERCE')->last()['text'])->toBe('🔴 [Critical] Đã có bản vá, nâng lên 8.3.36')
        ->and(chatJson(chatMessagesTo('COMMERCE')->last()))->not->toContain('Mức độ tăng')
        ->and(chatMessagesTo('BOOKING'))->toHaveCount(0);

    $this->travel(15)->minutes();

    expect(app(SendPreNoticeAlerts::class)->handle())->toBe(0);

    $this->travelTo('2026-12-20 00:00:00');
    $this->preNotice->update(['patch_versions' => ['8.3.37']]);
    RuntimeRelease::factory()->security()->create(['branch' => '8.3', 'version' => '8.3.37']);

    expect(app(SendPreNoticeAlerts::class)->handle())->toBe(1)
        ->and(chatMessagesTo('COMMERCE')->last()['text'])->toBe('🔴 [Critical] Đã có bản vá, nâng lên 8.3.37')
        ->and(chatMessagesTo('BOOKING'))->toHaveCount(0);
})->with([
    'told by an incident card' => false,
    'told by a card from before incidents' => true,
]);

it('sends no "Đã có bản vá" card for an incident only the digest tells about', function () {
    runtimeOnBranch($this->commerce, 'shop-api', '8.3.35');
    $this->preNotice->update(['severity' => Severity::Medium, 'patch_versions' => ['8.3.36']]);
    RuntimeRelease::factory()->security()->create(['branch' => '8.3', 'version' => '8.3.36']);
    IncidentTeamNotice::create(['team_id' => $this->commerce->id, 'advisory_id' => $this->preNotice->id, 'severity' => Severity::Unknown, 'reason' => 'new']);

    expect(app(SendPreNoticeAlerts::class)->handle())->toBe(0);

    $this->travelTo('2026-11-09 21:00:00');

    expect(app(SendPreNoticeAlerts::class)->handle())->toBe(0)
        ->and(IncidentTeamPatch::count())->toBe(0);
});

it('gives the card and the findings it stands for one deadline, never later than one a finding already had', function () {
    $this->travelTo('2026-10-21 15:00:00');
    $this->preNotice->update(['patch_versions' => ['8.3.36']]);
    RuntimeRelease::factory()->security()->create(['branch' => '8.3', 'version' => '8.3.36']);
    $later = patchableFinding(runtimeOnBranch($this->commerce, 'shop-api', '8.3.35'), '8.3.36');
    $earlier = patchableFinding(runtimeOnBranch($this->commerce, 'shop-web', '8.3.34'), '8.3.36', ['sla_due_at' => now()->addDay()]);

    app(SendPreNoticeAlerts::class)->handle();

    expect(chatJson(chatMessagesTo('COMMERCE')->sole()))->toContain('Hạn xử lý: 22/10/2026.')
        ->and($earlier->refresh()->sla_due_at->toDateTimeString())->toBe(now()->addDay()->toDateTimeString())
        ->and($later->refresh()->sla_due_at->toDateTimeString())->toBe(now()->addDays(3)->toDateTimeString());
});

it('keeps the finding the card stands for when an evaluation read before the card is stored after it', function () {
    $runtime = runtimeOnBranch($this->commerce, 'shop-api', '8.3.35');
    $runtime->update(['status' => RuntimeStatus::OutdatedSecurity, 'status_context' => ['latest' => '8.3.36']]);
    app(EvaluateRuntimes::class)->syncRuntimeFindings([$runtime->deployment_id]);
    $stale = app(RuntimeFindings::class)->for($runtime->refresh());

    $this->preNotice->update(['patch_versions' => ['8.3.36']]);
    RuntimeRelease::factory()->security()->create(['branch' => '8.3', 'version' => '8.3.36']);
    app(SendPreNoticeAlerts::class)->handle();
    app(SyncFindings::class)->handle($runtime->deployment, RuntimeFindings::RULES, [$stale]);

    $finding = Finding::sole();
    expect($stale->severity)->toBe(Severity::High)
        ->and($finding)->severity->toBe(Severity::Critical)->notified_severity->toBe(Severity::Critical)
        ->and($finding->context['covered_by_patch_id'])->toBe(IncidentTeamPatch::sole()->id)
        ->and(app(SendFindingAlerts::class)->handle())->toBe(0);
});

it('lists only the services below the fixed version as needing the upgrade', function () {
    runtimeOnBranch($this->commerce, 'shop-api', '8.3.35');
    runtimeOnBranch($this->commerce, 'shop-web', '8.3.37');
    $this->preNotice->update(['patch_versions' => ['8.3.36']]);
    RuntimeRelease::factory()->security()->create(['branch' => '8.3', 'version' => '8.3.36']);

    app(SendPreNoticeAlerts::class)->handle();

    expect(chatJson(chatMessagesTo('COMMERCE')->sole()))
        ->toContain('1 service cần nâng cấp', 'PHP 8.3.35 → 8.3.36', '1 service đã ở bản vá hoặc mới hơn.')
        ->not->toContain('PHP 8.3.37 → 8.3.36', 'shop-web', 'nhánh chưa có bản vá');
});

it('shows a branch whose fix an earlier card announced as having one, not as waiting', function () {
    runtimeOnBranch($this->commerce, 'shop-api', '8.3.35');
    runtimeOnBranch($this->commerce, 'shop-web', '8.3.36');
    runtimeOnBranch($this->commerce, 'shop-admin', '8.4.26');
    $this->preNotice->update(['patch_versions' => ['8.3.36']]);
    RuntimeRelease::factory()->security()->create(['branch' => '8.3', 'version' => '8.3.36']);
    app(SendPreNoticeAlerts::class)->handle();

    $this->travel(1)->hour();
    $this->preNotice->update(['patch_versions' => ['8.3.36', '8.4.27']]);
    RuntimeRelease::factory()->security()->create(['branch' => '8.4', 'version' => '8.4.27']);

    expect(app(SendPreNoticeAlerts::class)->handle())->toBe(1);

    $card = chatMessagesTo('COMMERCE')->last();
    expect($card['text'])->toBe('🔴 [Critical] Đã có bản vá, nâng lên 8.4.27')
        ->and(chatJson($card))->toContain('Hãng đã xác nhận bản vá 8.4.27.', '2 service cần nâng cấp', 'PHP 8.3.35 → 8.3.36', 'PHP 8.4.26 → 8.4.27', '1 service đã ở bản vá hoặc mới hơn.')
        ->not->toContain('nhánh chưa có bản vá', 'Phạm vi:');
});

it('sends the card of a covered finding once it asks for a later release than the card\'s', function () {
    $runtime = runtimeOnBranch($this->commerce, 'shop-api', '8.3.35');
    $runtime->update(['status' => RuntimeStatus::OutdatedSecurity, 'status_context' => ['latest' => '8.3.36']]);
    app(EvaluateRuntimes::class)->syncRuntimeFindings([$runtime->deployment_id]);
    $this->preNotice->update(['patch_versions' => ['8.3.36']]);
    RuntimeRelease::factory()->security()->create(['branch' => '8.3', 'version' => '8.3.36']);
    app(SendPreNoticeAlerts::class)->handle();
    $deadline = Finding::sole()->sla_due_at;

    expect(Finding::sole())->severity->toBe(Severity::Critical)->notified_severity->toBe(Severity::Critical);

    $this->travel(1)->day();
    RuntimeRelease::factory()->security()->create(['branch' => '8.3', 'version' => '8.3.40']);
    $runtime->update(['status_context' => ['latest' => '8.3.40']]);
    app(EvaluateRuntimes::class)->syncRuntimeFindings([$runtime->deployment_id]);

    $finding = Finding::sole();
    expect($finding)->severity->toBe(Severity::High)->notified_severity->toBeNull()
        ->and($finding->sla_due_at->toDateTimeString())->toBe($deadline->toDateTimeString())
        ->and($finding->context)->not->toHaveKey('covered_by_patch_id');

    $this->travel(7)->hours();

    expect(app(SendFindingAlerts::class)->handle())->toBe(1)
        ->and(chatJson(chatMessagesTo('COMMERCE')->last()))->toContain('PHP 8.3 thiếu bản vá bảo mật, cần nâng lên 8.3.40')
        ->and(app(SendFindingAlerts::class)->handle())->toBe(0);
});

it('groups the services of numbered branches such as those of Node.js by their fix', function () {
    $notice = Advisory::factory()->preNotice(Technology::Nodejs)->create(['title' => 'Node.js security releases', 'published_at' => now()->subHour(), 'patch_versions' => ['22.20.1', '24.9.2']]);
    runtimeAt($this->commerce, 'events', Technology::Nodejs, '22.20.0')->update(['version' => '22.20.0', 'branch' => '22']);
    runtimeAt($this->commerce, 'notifier', Technology::Nodejs, '24.9.0')->update(['version' => '24.9.0', 'branch' => '24']);
    RuntimeRelease::factory()->security()->create(['technology' => Technology::Nodejs, 'branch' => '22', 'version' => '22.20.1']);
    RuntimeRelease::factory()->security()->create(['technology' => Technology::Nodejs, 'branch' => '24', 'version' => '24.9.2']);
    $this->preNotice->delete();

    app(SendPreNoticeAlerts::class)->handle();

    expect(chatJson(chatMessagesTo('COMMERCE')->sole()))
        ->toContain('Đã có bản vá, nâng lên', '2 service cần nâng cấp', '22.20.0 → 22.20.1', '24.9.0 → 24.9.2')
        ->not->toContain('nhánh chưa có bản vá')
        ->and(IncidentTeamPatch::orderBy('branch')->pluck('branch')->all())->toBe(['22', '24'])
        ->and($notice->refresh()->incident_id)->not->toBeNull();
});

it('takes the lock of the technology\'s incidents before locking any row', function () {
    runtimeOnBranch($this->commerce, 'shop-api', '8.3.35');
    app(AssignNoticeToIncident::class)->assignUnassigned();
    DB::enableQueryLog();

    app(SendPreNoticeAlerts::class)->handle();

    $queries = collect(DB::getQueryLog());
    $lock = $queries->search(fn (array $query): bool => str_contains($query['query'], 'pg_advisory_xact_lock') && $query['bindings'] === [crc32('incidents:php')]);
    expect($lock)->toBeInt()
        ->and($lock)->toBeLessThan($queries->search(fn (array $query): bool => str_contains($query['query'], 'for update')));
});
