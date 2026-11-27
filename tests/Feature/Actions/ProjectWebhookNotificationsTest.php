<?php

use App\Actions\MergeIncidents;
use App\Actions\ResumeProjectMonitoring;
use App\Actions\SendDailyDigest;
use App\Actions\SendFindingAlerts;
use App\Actions\SendIncidentUpdate;
use App\Actions\SendPreNoticeAlerts;
use App\Enums\ChatNotificationKind;
use App\Enums\FindingEventType;
use App\Enums\IncidentImpact;
use App\Enums\NotificationTarget;
use App\Enums\Severity;
use App\Enums\Technology;
use App\Models\Advisory;
use App\Models\ChatNotification;
use App\Models\Deployment;
use App\Models\Finding;
use App\Models\IncidentTeam;
use App\Models\IncidentTeamNotice;
use App\Models\Project;
use App\Models\Runtime;
use App\Models\RuntimeRelease;
use App\Models\Service;
use App\Models\Team;
use App\Models\User;

beforeEach(function () {
    $this->travelTo('2026-10-20 21:00:00');
    fakeGoogleChat();
    $this->commerce = Team::factory()->create(['name' => 'Commerce', 'google_chat_webhook_url' => chatWebhook('COMMERCE')]);
    $this->shop = Project::factory()->for($this->commerce)->withOwnWebhook(chatWebhook('SHOP'))->create(['name' => 'Shop']);
});

/**
 * A PHP runtime on a known branch of a service of `$project`, created with the service when there is none.
 */
function projectPhpRuntime(Project $project, string $service, string $version): Runtime
{
    $service = Service::where('name', $service)->first() ?? Service::factory()->for($project)->create(['name' => $service, 'slug' => $service]);
    $deployment = $service->productionDeployment ?? $service->deployments()->create(['environment' => 'production']);
    $branch = implode('.', array_slice(explode('.', $version), 0, 2));

    return $deployment->runtimes()->create(['technology' => Technology::Php, 'raw_version' => $version, 'version' => $version, 'branch' => $branch, 'source' => 'manual', 'observed_at' => now()]);
}

function recipientFinding(Project $project, string $service, array $attributes = []): Finding
{
    $deployment = Deployment::factory()->for(Service::factory()->for($project)->state(['name' => $service]))->create();

    return Finding::factory()->for($deployment)->create(['group_key' => 'runtime_eol:1:8.1', ...$attributes]);
}

/**
 * A PHP pre-notice the alerts send right away, and a runtime of the team's own project and of the Shop project.
 */
function preNoticeForBoth(object $test): Advisory
{
    $preNotice = Advisory::factory()->preNotice(Technology::Php)->create([
        'title' => 'Upcoming Critical PHP Security Release',
        'severity' => Severity::High,
        'aliases' => ['CVE-2026-1001'],
        'published_at' => '2026-10-20 20:32:00',
    ]);

    projectPhpRuntime(Project::factory()->for($test->commerce)->create(['name' => 'Back office']), 'admin-api', '8.3.35');
    projectPhpRuntime($test->shop, 'shop-api', '8.2.20');

    return $preNotice;
}

it('sends a project on its own webhook its own card of an issue and leaves it out of the team card', function () {
    recipientFinding(Project::factory()->for($this->commerce)->create(), 'admin-api');
    recipientFinding($this->shop, 'shop-api');

    expect(app(SendFindingAlerts::class)->handle())->toBe(2);

    $teamCard = chatJson(chatMessagesTo('COMMERCE')->sole());
    $shopCard = chatJson(chatMessagesTo('SHOP')->sole());
    expect($teamCard)->toContain('admin-api')->not->toContain('shop-api', 'Commerce · Shop')
        ->and($shopCard)->toContain('shop-api', 'Commerce · Shop')->not->toContain('admin-api')
        ->and(ChatNotification::where('project_id', $this->shop->id)->sole())->team_id->toBe($this->commerce->id)
        ->and(app(SendFindingAlerts::class)->handle())->toBe(0);
});

it('tells the team again about a project that goes back to it', function () {
    recipientFinding($this->shop, 'shop-api');
    app(SendFindingAlerts::class)->handle();

    $this->shop->update(['notification_target' => NotificationTarget::Team]);
    recipientFinding($this->shop, 'shop-web');
    app(SendFindingAlerts::class)->handle();

    expect(chatMessagesTo('SHOP'))->toHaveCount(1)
        ->and(chatJson(chatMessagesTo('COMMERCE')->sole()))->toContain('shop-api', 'shop-web');
});

it('never falls back to the team when the project webhook fails, and tells the admin space', function () {
    $this->shop->update(['google_chat_webhook_url' => null]);
    recipientFinding($this->shop, 'shop-api');

    app(SendFindingAlerts::class)->handle();

    expect(chatMessagesTo('COMMERCE'))->toBeEmpty()
        ->and(ChatNotification::where('project_id', $this->shop->id)->sole())->failed_at->not->toBeNull()->last_error->toBe('Project chưa có webhook riêng.')
        ->and(chatJson(chatMessagesTo('ADMIN')->sole()))->toContain('Không gửi được tin tới team Commerce · Shop', 'Project chưa có webhook riêng.');
});

it('counts the project apart in the team status, since its webhook is not the team one', function () {
    $this->shop->update(['google_chat_webhook_url' => null]);
    recipientFinding($this->shop, 'shop-api');
    app(SendFindingAlerts::class)->handle();

    expect($this->commerce->refresh()->googleChatStatus()->value)->toBe('active')
        ->and($this->shop->refresh()->googleChatStatus()->value)->toBe('not_configured');
});

it('sends each recipient a daily digest of its own services', function () {
    $this->travelTo('2026-10-24 01:00:00');
    $backOffice = Project::factory()->for($this->commerce)->create();

    foreach ([[$backOffice, 'admin-api', 'PHP 8.1 hết hỗ trợ'], [$this->shop, 'shop-api', 'Node.js 18 hết hỗ trợ']] as [$project, $service, $title]) {
        recipientFinding($project, $service, ['title' => $title])->events()->create(['type' => FindingEventType::Opened])->forceFill(['created_at' => '2026-10-23 10:00:00'])->save();
    }

    expect(app(SendDailyDigest::class)->handle())->toBe(2);

    expect(chatJson(chatMessagesTo('COMMERCE')->sole()))->toContain('PHP 8.1 hết hỗ trợ')->not->toContain('Node.js 18')
        ->and(chatJson(chatMessagesTo('SHOP')->sole()))->toContain('Node.js 18 hết hỗ trợ', 'Commerce · Shop')->not->toContain('PHP 8.1')
        ->and(app(SendDailyDigest::class)->handle())->toBe(0);
});

it('opens a thread of the incident in the project space and records what the team was told once', function () {
    $preNotice = preNoticeForBoth($this);

    expect(app(SendPreNoticeAlerts::class)->handle())->toBe(1);

    $teamRow = IncidentTeam::whereNull('project_id')->sole();
    $shopRow = IncidentTeam::where('project_id', $this->shop->id)->sole();
    $shopCard = chatRequestsTo('SHOP')->sole();

    expect(chatJson(chatMessagesTo('COMMERCE')->sole()))->toContain('admin-api')->not->toContain('shop-api')
        ->and(chatJson($shopCard->data()))->toContain('shop-api', 'Cảnh báo chuẩn bị · Commerce · Shop')->not->toContain('admin-api')
        ->and($shopRow->thread_key)->toEndWith("-team-{$this->commerce->id}-project-{$this->shop->id}-g1")->not->toBe($teamRow->thread_key)
        ->and($shopCard->data()['thread']['threadKey'])->toBe($shopRow->thread_key)
        ->and($shopRow->space)->toBe('SHOP')
        ->and($shopRow->thread_name)->toStartWith('spaces/SHOP/threads/')
        ->and(IncidentTeamNotice::where('advisory_id', $preNotice->id)->sole()->team_id)->toBe($this->commerce->id)
        ->and(app(SendPreNoticeAlerts::class)->handle())->toBe(0);
});

it('replies in the thread of each recipient when the severity rises', function () {
    $preNotice = preNoticeForBoth($this);
    app(SendPreNoticeAlerts::class)->handle();

    $this->travel(1)->hour();
    $preNotice->update(['severity' => Severity::Critical]);

    expect(app(SendPreNoticeAlerts::class)->handle())->toBe(1);

    [$opener, $reply] = chatMessagesTo('SHOP')->all();
    expect($reply['thread']['threadKey'])->toBe($opener['thread']['threadKey'])
        ->and(chatJson($reply))->toContain('Cập nhật · Commerce · Shop', 'Mức độ tăng từ High lên Critical.', 'shop-api')->not->toContain('admin-api')
        ->and(chatMessagesTo('COMMERCE'))->toHaveCount(2);
});

it('tells a fix only to the recipient that runs its branch', function () {
    $preNotice = preNoticeForBoth($this);
    app(SendPreNoticeAlerts::class)->handle();

    $this->travel(1)->hour();
    RuntimeRelease::factory()->security()->create(['branch' => '8.3', 'version' => '8.3.36']);
    $preNotice->update(['patch_versions' => ['8.3.36']]);

    expect(app(SendPreNoticeAlerts::class)->handle())->toBe(1)
        ->and(chatJson(chatMessagesTo('COMMERCE')->last()))->toContain('Hãng đã xác nhận bản vá 8.3.36.', 'admin-api')
        ->and(chatMessagesTo('SHOP'))->toHaveCount(1);
});

it('withholds the project messages too while an admin says the incident does not affect the team', function () {
    $preNotice = preNoticeForBoth($this);
    app(SendPreNoticeAlerts::class)->handle();
    $shopReply = ChatNotification::factory()->for($this->commerce)->create([
        'project_id' => $this->shop->id,
        'kind' => ChatNotificationKind::PreNotice,
        'incident_team_id' => IncidentTeam::where('project_id', $this->shop->id)->sole()->id,
    ]);

    assessIncident($preNotice->refresh()->incident, $this->commerce, IncidentImpact::No);

    expect($shopReply->isWithheldByAssessment())->toBeTrue();
});

it('opens a new thread in the project space when its webhook changes', function () {
    $preNotice = preNoticeForBoth($this);
    app(SendPreNoticeAlerts::class)->handle();

    $this->shop->update(['google_chat_webhook_url' => chatWebhook('SHOP2')]);
    $this->travel(1)->hour();
    $preNotice->update(['severity' => Severity::Critical]);
    app(SendPreNoticeAlerts::class)->handle();

    $shopRow = IncidentTeam::where('project_id', $this->shop->id)->sole();
    expect($shopRow)->generation->toBe(2)->space->toBe('SHOP2')
        ->and($shopRow->thread_key)->toEndWith("-project-{$this->shop->id}-g2")
        ->and(chatJson(chatMessagesTo('SHOP2')->sole()))->toContain('Cảnh báo chuẩn bị · Commerce · Shop', 'Mức độ tăng từ High lên Critical.');
});

it('keeps the project thread when its incident is merged into another', function () {
    $preNotice = preNoticeForBoth($this);
    $other = Advisory::factory()->preNotice(Technology::Php)->create(['title' => 'Other PHP issue', 'severity' => Severity::Medium, 'aliases' => ['CVE-2026-2002'], 'published_at' => now()->subHour()]);
    app(SendPreNoticeAlerts::class)->handle();
    $fromThread = IncidentTeam::where('project_id', $this->shop->id)->sole();

    $into = app(MergeIncidents::class)->handle($preNotice->refresh()->incident, $other->refresh()->incident, User::factory()->create(['is_admin' => true]));

    $intoThread = IncidentTeam::where('incident_id', $into->id)->where('project_id', $this->shop->id)->sole();
    expect($fromThread->refresh()->merged_into_id)->toBe($intoThread->id)
        ->and($intoThread)->thread_key->toBe($fromThread->thread_key)->thread_name->toBe($fromThread->thread_name);

    $this->travel(1)->hour();
    $preNotice->update(['severity' => Severity::Critical]);
    app(SendPreNoticeAlerts::class)->handle();

    expect(chatMessagesTo('SHOP')->last()['thread']['threadKey'])->toBe($fromThread->thread_key);
});

it('sends an admin update to every recipient of the team in its own thread', function () {
    $preNotice = preNoticeForBoth($this);
    app(SendPreNoticeAlerts::class)->handle();

    $result = app(SendIncidentUpdate::class)->handle($preNotice->refresh()->incident, 'Tắt mod_php cho tới khi nâng cấp.');

    expect($result['queued'])->toBe(1)
        ->and(chatJson(chatMessagesTo('SHOP')->last()))->toContain('Tắt mod_php cho tới khi nâng cấp.', 'Cập nhật · Commerce · Shop')
        ->and(chatMessagesTo('SHOP')->last()['thread']['threadKey'])->toBe(IncidentTeam::where('project_id', $this->shop->id)->sole()->thread_key)
        ->and(chatJson(chatMessagesTo('COMMERCE')->last()))->toContain('Tắt mod_php cho tới khi nâng cấp.')
        ->and(ChatNotification::where('kind', ChatNotificationKind::IncidentUpdate)->count())->toBe(2);
});

it('tells the project space, not the team, that a paused project is monitored again', function () {
    $this->shop->update(['monitoring_paused_at' => now(), 'monitoring_pause_reason' => 'Tạm ngừng.']);

    app(ResumeProjectMonitoring::class)->handle(User::factory()->create(['is_admin' => true]), $this->shop, notify: true, note: null);

    expect(chatJson(chatMessagesTo('SHOP')->sole()))->toContain('Theo dõi lại project · Commerce · Shop')
        ->and(chatMessagesTo('COMMERCE'))->toBeEmpty();
});
