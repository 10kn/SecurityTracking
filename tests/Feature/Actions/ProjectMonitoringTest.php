<?php

use App\Actions\PauseProjectMonitoring;
use App\Actions\ResumeProjectMonitoring;
use App\Actions\SendDailyDigest;
use App\Actions\SendFindingAlerts;
use App\Actions\SendPreNoticeAlerts;
use App\Enums\ChatNotificationKind;
use App\Enums\FindingRule;
use App\Enums\IncidentImpact;
use App\Enums\IncidentTeamEventType;
use App\Enums\Severity;
use App\Enums\Technology;
use App\Models\Advisory;
use App\Models\ChatNotification;
use App\Models\Finding;
use App\Models\IncidentTeam;
use App\Models\IncidentTeamEvent;
use App\Models\IncidentTeamPatch;
use App\Models\Project;
use App\Models\ProjectIncidentSkip;
use App\Models\Runtime;
use App\Models\RuntimeRelease;
use App\Models\Team;
use App\Models\User;
use App\Services\IncidentTeamExposure;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\get;

beforeEach(function () {
    $this->travelTo('2026-10-20 21:00:00');
    fakeGoogleChat();
    $this->admin = User::factory()->admin()->create(['name' => 'Lan']);
    $this->commerce = Team::factory()->create(['name' => 'Commerce', 'google_chat_webhook_url' => chatWebhook('COMMERCE')]);
    $this->preNotice = Advisory::factory()->preNotice(Technology::Php)->create([
        'title' => 'Upcoming Critical PHP Security Release',
        'aliases' => ['CVE-2026-1001'],
        'published_at' => '2026-10-20 20:32:00',
    ]);
});

/**
 * A PHP runtime of the team's service on a known branch, in a project of its own.
 */
function projectRuntime(Team $team, string $service, string $version): Runtime
{
    $runtime = runtimeAt($team, $service, Technology::Php, $version);
    $runtime->update(['version' => $version, 'branch' => implode('.', array_slice(explode('.', $version), 0, 2))]);

    return $runtime->refresh();
}

function projectOf(Runtime $runtime): Project
{
    return $runtime->deployment->service->project;
}

/**
 * An open finding of the runtime: a "thiếu bản vá" one asking for `$latest`, at High.
 */
function projectFinding(Runtime $runtime, string $latest = '8.3.36', array $attributes = []): Finding
{
    return Finding::factory()->for($runtime->deployment)->create([
        'rule' => FindingRule::RuntimeOutdatedSecurity,
        'runtime_id' => $runtime->id,
        'severity' => Severity::High,
        'group_key' => "runtime_outdated_security:php:{$latest}",
        'context' => ['latest' => $latest, 'branch' => $runtime->branch],
        'sla_due_at' => now()->addDays(14),
        ...$attributes,
    ]);
}

it('leaves the services of a paused project out of the pre-notice card, and sends none to a team running only those', function () {
    projectRuntime($this->commerce, 'shop-api', '8.3.35');
    $legacy = projectRuntime($this->commerce, 'legacy-api', '8.3.35');
    $booking = Team::factory()->create(['google_chat_webhook_url' => chatWebhook('BOOKING')]);
    $bookingApi = projectRuntime($booking, 'booking-api', '8.4.26');
    app(PauseProjectMonitoring::class)->handle($this->admin, projectOf($legacy), 'Đã ngừng maintain.');
    app(PauseProjectMonitoring::class)->handle($this->admin, projectOf($bookingApi), 'Đã ngừng maintain.');

    expect(app(SendPreNoticeAlerts::class)->handle())->toBe(1)
        ->and(chatJson(chatMessagesTo('COMMERCE')->sole()))->toContain('shop-api', '1 service đang chạy PHP')->not->toContain('legacy-api')
        ->and(chatMessagesTo('BOOKING'))->toBeEmpty();
});

it('neither sends nor counts as sent the findings of a paused project, nor puts them in the digest', function () {
    $runtime = projectRuntime($this->commerce, 'legacy-api', '8.1.31');
    $finding = Finding::factory()->for($runtime->deployment)->create(['severity' => Severity::High, 'group_key' => 'runtime_eol:php:8.1']);
    app(PauseProjectMonitoring::class)->handle($this->admin, projectOf($runtime), 'Đã ngừng maintain.');

    expect(app(SendFindingAlerts::class)->handle())->toBe(0)
        ->and($finding->refresh()->notified_severity)->toBeNull()
        ->and(app(SendDailyDigest::class)->handle())->toBe(0)
        ->and(chatMessagesTo('COMMERCE'))->toBeEmpty();
});

it('does not count the "thiếu bản vá" findings of a paused project as told by a "Đã có bản vá" card', function () {
    $this->travelTo('2026-10-21 15:00:00');
    $shop = projectRuntime($this->commerce, 'shop-api', '8.3.35');
    $legacy = projectRuntime($this->commerce, 'legacy-api', '8.3.35');
    $this->preNotice->update(['patch_versions' => ['8.3.36']]);
    RuntimeRelease::factory()->security()->create(['branch' => '8.3', 'version' => '8.3.36']);
    $shopFinding = projectFinding($shop);
    $legacyFinding = projectFinding($legacy);
    app(PauseProjectMonitoring::class)->handle($this->admin, projectOf($legacy), 'Đã ngừng maintain.');

    app(SendPreNoticeAlerts::class)->handle();

    expect($shopFinding->refresh()->notified_severity)->not->toBeNull()
        ->and($legacyFinding->refresh()->notified_severity)->toBeNull()
        ->and($legacyFinding->context)->not->toHaveKey('covered_by_patch_id')
        ->and(IncidentTeamPatch::sole()->project_ids)->toBe([projectOf($shop)->id]);
});

it('resumes a project telling its team what it missed, then sends what was not told yet', function () {
    $runtime = projectRuntime($this->commerce, 'legacy-api', '8.3.35');
    $finding = Finding::factory()->for($runtime->deployment)->create(['severity' => Severity::High, 'group_key' => 'runtime_eol:php:8.3']);
    $project = projectOf($runtime);
    app(PauseProjectMonitoring::class)->handle($this->admin, $project, 'Đã ngừng maintain.');
    app(SendPreNoticeAlerts::class)->handle();
    app(SendFindingAlerts::class)->handle();

    expect(chatMessagesTo('COMMERCE'))->toBeEmpty()
        ->and(app(ResumeProjectMonitoring::class)->handle($this->admin, $project, true, 'Khách hàng ký hợp đồng mới.'))->toBeTrue();

    $card = chatMessagesTo('COMMERCE')->sole();
    expect(ChatNotification::sole()->kind)->toBe(ChatNotificationKind::ProjectResumed)
        ->and(chatJson($card))->toContain("Project {$project->name} được theo dõi lại", 'Mở lại bởi Lan · Khách hàng ký hợp đồng mới.', 'Finding đang mở: 1 (High: 1)', 'Upcoming Critical PHP Security Release');

    app(SendPreNoticeAlerts::class)->handle();
    app(SendFindingAlerts::class)->handle();

    expect(chatMessagesTo('COMMERCE'))->toHaveCount(3)
        ->and($finding->refresh()->notified_severity)->toBe(Severity::High);
});

it('resumes a project without telling its team, until the incident asks more of it', function () {
    $runtime = projectRuntime($this->commerce, 'legacy-api', '8.3.35');
    $finding = Finding::factory()->for($runtime->deployment)->create(['severity' => Severity::High, 'group_key' => 'runtime_eol:php:8.3']);
    $project = projectOf($runtime);
    app(PauseProjectMonitoring::class)->handle($this->admin, $project, 'Đã ngừng maintain.');
    app(SendPreNoticeAlerts::class)->handle();

    app(ResumeProjectMonitoring::class)->handle($this->admin, $project, false, null);

    expect(app(SendPreNoticeAlerts::class)->handle())->toBe(0)
        ->and(app(SendFindingAlerts::class)->handle())->toBe(0)
        ->and(chatMessagesTo('COMMERCE'))->toBeEmpty()
        ->and($finding->refresh())->notified_severity->toBe(Severity::High)->acknowledged_by->toBe($this->admin->id)
        ->and(ProjectIncidentSkip::sole())->project_id->toBe($project->id)->incident_id->toBe($this->preNotice->refresh()->incident_id);

    $this->preNotice->update(['is_exploited' => true, 'exploited_basis' => ['method' => 'keyword', 'excerpt' => 'Actively exploited in the wild.', 'content_hash' => 'x']]);

    expect(app(SendPreNoticeAlerts::class)->handle())->toBe(1)
        ->and(chatJson(chatMessagesTo('COMMERCE')->sole()))->toContain('legacy-api');
});

it('restarts the deadlines of the open findings from the day it is resumed, never past the CISA KEV due date', function () {
    $runtime = projectRuntime($this->commerce, 'legacy-api', '8.3.35');
    $late = Finding::factory()->for($runtime->deployment)->create(['severity' => Severity::High, 'group_key' => 'a', 'sla_due_at' => now()->subDays(10)]);
    $known = Finding::factory()->for($runtime->deployment)->create(['severity' => Severity::High, 'group_key' => 'b', 'sla_due_at' => now()->subDays(10), 'context' => ['known_exploited' => [['cve' => 'CVE-2026-1001', 'date_added' => '2026-10-01', 'due_date' => '2026-10-23']]]]);
    $project = projectOf($runtime);
    app(PauseProjectMonitoring::class)->handle($this->admin, $project, 'Đã ngừng maintain.');

    app(ResumeProjectMonitoring::class)->handle($this->admin, $project, true, null);

    expect($late->refresh()->sla_due_at->toDateTimeString())->toBe('2026-11-03 21:00:00')
        ->and($known->refresh()->sla_due_at->toDateString())->toBe('2026-10-23');
});

it('resets the team\'s assessment of an incident affecting the resumed project', function () {
    projectRuntime($this->commerce, 'shop-api', '8.3.35');
    $legacy = projectRuntime($this->commerce, 'legacy-api', '8.3.35');
    $project = projectOf($legacy);
    app(PauseProjectMonitoring::class)->handle($this->admin, $project, 'Đã ngừng maintain.');
    app(SendPreNoticeAlerts::class)->handle();
    $incident = $this->preNotice->refresh()->incident;
    assessIncident($incident, $this->commerce, IncidentImpact::No, $this->admin);

    app(ResumeProjectMonitoring::class)->handle($this->admin, $project, true, null);

    expect(IncidentTeam::where('incident_id', $incident->id)->sole()->impact)->toBeNull()
        ->and(IncidentTeamEvent::where('type', IncidentTeamEventType::ImpactReset)->sole())
        ->user_id->toBe($this->admin->id)
        ->data->toMatchArray(['project' => $project->name]);
});

it('shows the incident page the exposure an admin can mark as read, without the paused project', function () {
    actingAsAdmin();
    projectRuntime($this->commerce, 'shop-api', '8.3.35');
    $legacy = projectRuntime($this->commerce, 'legacy-api', '8.3.35');
    app(PauseProjectMonitoring::class)->handle($this->admin, projectOf($legacy), 'Đã ngừng maintain.');
    app(SendPreNoticeAlerts::class)->handle();
    $incident = $this->preNotice->refresh()->incident;

    get("/admin/incidents/{$incident->id}")->assertInertia(fn (Assert $page) => $page
        ->where('teams.0.exposure_hash', IncidentTeamExposure::for($incident, $this->commerce)->hash())
        ->etc());

    expect(collect(IncidentTeamExposure::for($incident, $this->commerce)->toArray()['services'])->pluck('service')->all())->toBe(['shop-api']);
});
