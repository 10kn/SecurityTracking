<?php

use App\Actions\SendPreNoticeAlerts;
use App\Enums\FindingState;
use App\Enums\IncidentImpact;
use App\Enums\RuntimeStatus;
use App\Enums\Severity;
use App\Enums\Technology;
use App\Models\Advisory;
use App\Models\ChatNotification;
use App\Models\Deployment;
use App\Models\Finding;
use App\Models\Incident;
use App\Models\IncidentSuggestion;
use App\Models\Lockfile;
use App\Models\Project;
use App\Models\Runtime;
use App\Models\Service;
use App\Models\SourceHeartbeat;
use App\Models\Team;
use App\Services\Repositories\RepositoryReader;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\get;

beforeEach(function () {
    $this->travelTo('2026-10-24 10:00:00');
    config(['psm.alerts.admin_webhook' => chatWebhook('ADMIN')]);

    foreach (Technology::cases() as $technology) {
        SourceHeartbeat::factory()->create(['source' => $technology->catalogSourceKey(), 'name' => "Danh mục version {$technology->getLabel()}"]);
    }
});

/**
 * A service of a team running PHP on production, evaluated as up to date unless told otherwise.
 */
function overviewService(string $name, ?Team $team = null, RuntimeStatus $status = RuntimeStatus::Ok, Technology $technology = Technology::Php): Service
{
    $service = Service::factory()->for(Project::factory()->for($team ?? Team::factory()->create(['google_chat_webhook_url' => chatWebhook('TEAM')])))->create(['name' => $name]);
    Runtime::factory()->for(Deployment::factory()->for($service)->create(['environment' => 'production', 'is_current' => true]))->create(['technology' => $technology, 'status' => $status]);

    return $service;
}

it('is the admin home', function () {
    get('/admin')->assertRedirect('/admin/login');

    actingAsAdmin();

    get('/admin')->assertOk()->assertInertia(fn (Assert $page) => $page->component('overview/index'));
});

it('reports nothing to fix when monitoring works, and still says what it checked', function () {
    actingAsAdmin();
    $commerce = Team::factory()->create(['name' => 'Commerce', 'google_chat_webhook_url' => chatWebhook('COMMERCE')]);
    overviewService('shop-api', $commerce);
    ChatNotification::factory()->for($commerce)->sent()->create(['sent_at' => '2026-10-24 01:00:00']);

    get('/admin')->assertInertia(fn (Assert $page) => $page
        ->where('checked.time', '17:00 GMT+7')
        ->has('sources', 3)
        ->where('sources.0.is_stale', false)
        ->where('delivery.destinations', [])
        ->where('delivery.last_sent', '24/10/2026 08:00 GMT+7')
        ->where('coverage.count', 0)
        ->where('findings', ['count' => 0, 'items' => []])
        ->where('incidents', ['count' => 0, 'items' => []]));
});

it('lists the sources over their threshold first, with what they miss and a rerun for catalogs', function () {
    actingAsAdmin();
    SourceHeartbeat::where('source', 'catalog:nodejs')->update(['last_success_at' => now()->subHours(4), 'last_error' => 'HTTP 503']);
    SourceHeartbeat::factory()->stale()->create(['source' => 'osv', 'name' => 'OSV', 'threshold_minutes' => 720, 'last_success_at' => now()->subHours(26)]);
    SourceHeartbeat::factory()->create(['source' => 'packagist', 'name' => 'Packagist', 'threshold_minutes' => 720]);
    SourceHeartbeat::factory()->create(['source' => 'kev', 'name' => 'CISA KEV', 'last_success_at' => null]);

    get('/admin')->assertInertia(fn (Assert $page) => $page
        ->has('sources', 6)
        ->where('sources.0.source', 'catalog:nodejs')
        ->where('sources.0.name', 'Danh mục version Node.js')
        ->where('sources.0.technology', 'nodejs')
        ->where('sources.0.last_success', '4 giờ trước')
        ->where('sources.0.threshold', '3 giờ')
        ->where('sources.0.error', 'HTTP 503')
        ->where('sources.0.consequence', 'Runtime Node.js được coi là Thiếu dữ liệu cho tới khi danh mục đồng bộ lại.')
        ->where('sources.1.source', 'kev')
        ->where('sources.1.last_success', null)
        ->where('sources.2.source', 'osv')
        ->where('sources.2.technology', null)
        ->where('sources.2.consequence', 'Có thể bỏ sót lỗ hổng mới của package npm. Finding đang mở vẫn giữ nguyên.')
        ->where('sources.3.is_stale', false));
});

it('shows the messages the admin space did not get, with their ids to resend', function () {
    actingAsAdmin();
    config(['psm.alerts.admin_webhook' => null]);
    $first = ChatNotification::factory()->forAdmin()->failed()->create(['created_at' => '2026-10-24 01:15:00', 'last_error' => 'Chưa cấu hình PSM_ADMIN_CHAT_WEBHOOK.']);
    $second = ChatNotification::factory()->forAdmin()->failed()->create(['created_at' => '2026-10-24 02:00:00', 'last_error' => 'Chưa cấu hình PSM_ADMIN_CHAT_WEBHOOK.']);

    get('/admin')->assertInertia(fn (Assert $page) => $page
        ->has('delivery.destinations', 1)
        ->where('delivery.destinations.0.team', null)
        ->where('delivery.destinations.0.has_webhook', false)
        ->where('delivery.destinations.0.count', 2)
        ->where('delivery.destinations.0.since', '24/10 08:15 GMT+7')
        ->where('delivery.destinations.0.error', 'Chưa cấu hình PSM_ADMIN_CHAT_WEBHOOK.')
        ->where('delivery.destinations.0.resend_ids', [$first->id, $second->id])
        ->where('delivery.last_sent', null));
});

it('flags the admin space without a webhook before any message failed', function () {
    actingAsAdmin();
    config(['psm.alerts.admin_webhook' => 'https://example.com/hook']);

    get('/admin')->assertInertia(fn (Assert $page) => $page
        ->has('delivery.destinations', 1)
        ->where('delivery.destinations.0.team', null)
        ->where('delivery.destinations.0.count', 0));
});

it('groups the messages teams did not get by team, with the teams without a webhook', function () {
    actingAsAdmin();
    $booking = Team::factory()->create(['name' => 'Booking', 'google_chat_webhook_url' => chatWebhook('BOOKING')]);
    $commerce = Team::factory()->create(['name' => 'Commerce', 'google_chat_webhook_url' => chatWebhook('COMMERCE')]);
    $internal = Team::factory()->create(['name' => 'Internal Tools']);
    $failed = ChatNotification::factory()->for($booking)->failed()->create(['created_at' => '2026-10-22 07:05:00', 'last_error' => 'Google Chat trả lỗi HTTP 404.']);
    ChatNotification::factory()->for($booking)->create(['created_at' => now()->subMinutes(45)]);
    ChatNotification::factory()->for($booking)->create(['created_at' => now()->subMinutes(5)]);
    ChatNotification::factory()->for($commerce)->failed()->create(['created_at' => now()->subDay()]);
    ChatNotification::factory()->for($commerce)->sent()->create(['created_at' => now()->subHour()]);

    get('/admin')->assertInertia(fn (Assert $page) => $page
        ->has('delivery.destinations', 2)
        ->where('delivery.destinations.0.team', ['id' => $booking->id, 'name' => 'Booking'])
        ->where('delivery.destinations.0.count', 2)
        ->where('delivery.destinations.0.stuck_count', 1)
        ->where('delivery.destinations.0.since', '22/10 14:05 GMT+7')
        ->where('delivery.destinations.0.error', 'Google Chat trả lỗi HTTP 404.')
        ->where('delivery.destinations.0.resend_ids', [$failed->id])
        ->where('delivery.destinations.1.team', ['id' => $internal->id, 'name' => 'Internal Tools'])
        ->where('delivery.destinations.1.has_webhook', false)
        ->where('delivery.destinations.1.count', 0));
});

it('counts the services not fully evaluated, and lists them on the services page', function () {
    actingAsAdmin();
    overviewService('unknown-runtime', status: RuntimeStatus::Unknown);
    overviewService('stale-catalog', technology: Technology::Nodejs);
    $unreadable = overviewService('unreadable-lockfile');
    $lockfile = Lockfile::factory()->for($unreadable->productionDeployment)->create(['parse_error' => 'Syntax error']);
    $lockfile->update(['parsed_hash' => $lockfile->content_hash]);
    $waiting = overviewService('lockfile-not-read-yet');
    Lockfile::factory()->for($waiting->productionDeployment)->create();
    overviewService('sync-error')->update(['lockfiles_sync_error' => 'Không đọc được repository.']);
    overviewService('evaluated');
    SourceHeartbeat::where('source', 'catalog:nodejs')->update(['last_success_at' => now()->subDay()]);

    get('/admin')->assertInertia(fn (Assert $page) => $page->where('coverage.count', 4));

    get('/admin/services?attention=1')->assertInertia(fn (Assert $page) => $page
        ->where('table.attention', 1)
        ->where('services.data', fn ($services) => collect($services)->pluck('name')->sort()->values()->all() === ['stale-catalog', 'sync-error', 'unknown-runtime', 'unreadable-lockfile'])
        ->where('services.data', fn ($services) => collect($services)->pluck('lockfiles_sync_error', 'name')->filter()->all() === ['sync-error' => 'Không đọc được repository.']));
});

it('lists the priority findings: CISA KEV, then overdue, then unread Critical, then unread High', function () {
    actingAsAdmin();
    $deployment = overviewService('shop-api', Team::factory()->create(['name' => 'Commerce']))->productionDeployment;
    $unreadHigh = Finding::factory()->for($deployment)->create(['severity' => Severity::High, 'sla_due_at' => now()->addDays(5)]);
    $unreadCritical = Finding::factory()->for($deployment)->create(['severity' => Severity::Critical, 'sla_due_at' => now()->addHours(14)]);
    $overdue = Finding::factory()->for($deployment)->create(['severity' => Severity::Medium, 'sla_due_at' => now()->subHours(6), 'acknowledged_at' => now()]);
    $knownExploited = Finding::factory()->for($deployment)->create([
        'severity' => Severity::Medium,
        'sla_due_at' => now()->addDays(20),
        'acknowledged_at' => now(),
        'context' => ['known_exploited' => [['cve' => 'CVE-2026-109618', 'name' => 'PHP RCE', 'date_added' => '2026-10-23', 'due_date' => null, 'listed' => true]]],
    ]);
    Finding::factory()->for($deployment)->create(['severity' => Severity::High, 'sla_due_at' => now()->addDays(5), 'acknowledged_at' => now()]);
    Finding::factory()->for($deployment)->create(['severity' => Severity::Medium, 'sla_due_at' => now()->addDays(5), 'context' => ['known_exploited' => []]]);
    Finding::factory()->for($deployment)->create(['severity' => Severity::Critical, 'state' => FindingState::Resolved]);

    get('/admin')->assertInertia(fn (Assert $page) => $page
        ->where('findings.count', 4)
        ->has('findings.items', 3)
        ->where('findings.items.0.id', $knownExploited->id)
        ->where('findings.items.0.is_known_exploited', true)
        ->where('findings.items.0.service', 'shop-api')
        ->where('findings.items.0.team', 'Commerce')
        ->where('findings.items.1.id', $overdue->id)
        ->where('findings.items.1.deadline', ['label' => 'Quá 6 giờ', 'is_overdue' => true])
        ->where('findings.items.1.is_read', true)
        ->where('findings.items.2.id', $unreadCritical->id)
        ->where('findings.items.2.deadline', ['label' => 'Còn 14 giờ', 'is_overdue' => false]));

    get('/admin/findings?priority=1')->assertInertia(fn (Assert $page) => $page
        ->where('table.priority', 1)
        ->where('findings.data', fn ($findings) => collect($findings)->pluck('id')->sort()->values()->all() === collect([$knownExploited, $overdue, $unreadCritical, $unreadHigh])->pluck('id')->sort()->values()->all()));
});

it('lists the incidents needing an admin, newest first, with why', function () {
    actingAsAdmin();
    fakeGoogleChat();
    $commerce = Team::factory()->create(['name' => 'Commerce', 'google_chat_webhook_url' => chatWebhook('COMMERCE')]);
    $booking = Team::factory()->create(['name' => 'Booking', 'google_chat_webhook_url' => chatWebhook('BOOKING')]);
    $preNotice = Advisory::factory()->preNotice(Technology::Php)->create(['title' => 'Upcoming Critical PHP Security Release', 'severity' => Severity::High, 'published_at' => now()->subHour()]);
    runtimeAt($commerce, 'shop-api', Technology::Php, '8.3.35')->update(['version' => '8.3.35', 'branch' => '8.3']);
    runtimeAt($booking, 'booking-api', Technology::Php, '8.4.26')->update(['version' => '8.4.26', 'branch' => '8.4']);
    app(SendPreNoticeAlerts::class)->handle();
    $incident = $preNotice->refresh()->incident;
    $suggested = Incident::factory()->create(['title' => 'nodejs.org báo trước bản vá bảo mật', 'technology' => Technology::Nodejs, 'first_published_at' => now()->subDays(3)]);
    IncidentSuggestion::factory()->create(['incident_id' => $suggested->id]);
    Incident::factory()->create(['first_published_at' => now()->subDays(90)]);

    get('/admin')->assertInertia(fn (Assert $page) => $page
        ->where('incidents.count', 2)
        ->where('incidents.items.0.id', $incident->id)
        ->where('incidents.items.0.title', 'Upcoming Critical PHP Security Release')
        ->where('incidents.items.0.reasons', ['2 team cần đánh giá ảnh hưởng'])
        ->where('incidents.items.1.id', $suggested->id)
        ->where('incidents.items.1.reasons', ['1 gợi ý gộp đang chờ']));

    assessIncident($incident, $commerce, IncidentImpact::Yes);
    assessIncident($incident, $booking, IncidentImpact::No);

    get('/admin')->assertInertia(fn (Assert $page) => $page->where('incidents.count', 1)->where('incidents.items.0.id', $suggested->id));
});

it('shows a block it cannot read as such and still shows the others', function () {
    actingAsAdmin();
    SourceHeartbeat::factory()->create(['source' => 'github', 'name' => 'GitHub']);
    $this->mock(RepositoryReader::class)->shouldReceive('sourceKey')->andThrow(new RuntimeException('Reader unavailable'));

    get('/admin')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('sources', null)
        ->where('delivery.destinations', [])
        ->where('findings.count', 0));
});
