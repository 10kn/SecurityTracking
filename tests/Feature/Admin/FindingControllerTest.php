<?php

use App\Actions\AcknowledgeFindings;
use App\Enums\FindingEventType;
use App\Enums\FindingRule;
use App\Enums\FindingState;
use App\Enums\Locale;
use App\Enums\Severity;
use App\Models\Deployment;
use App\Models\Finding;
use App\Models\Project;
use App\Models\Service;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

beforeEach(function () {
    $this->travelTo('2026-10-24 10:00:00');
    $this->commerce = Team::factory()->create(['name' => 'Commerce']);
    $this->deployment = Deployment::factory()->for(Service::factory()->for(Project::factory()->for($this->commerce))->state(['name' => 'shop-api']))->create();
});

it('lists the open findings, Critical first, with their deadline and who read them', function () {
    $admin = actingAsAdmin();
    Finding::factory()->for($this->deployment)->create(['title' => 'PHP 8.1 hết hỗ trợ', 'severity' => Severity::High, 'sla_due_at' => '2026-10-23 10:00:00', 'acknowledged_at' => now(), 'acknowledged_by' => $admin->id]);
    Finding::factory()->for($this->deployment)->create(['title' => 'GHSA-8xf4-w7qw-pjjw trong firebase/php-jwt', 'severity' => Severity::Critical, 'sla_due_at' => '2026-10-27 10:00:00']);
    Finding::factory()->for($this->deployment)->create(['state' => FindingState::Resolved]);

    get('/admin/findings')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('findings/index')
            ->has('findings.data', 2)
            ->where('findings.data.0.title', 'GHSA-8xf4-w7qw-pjjw trong firebase/php-jwt')
            ->where('findings.data.0.acknowledged', null)
            ->where('findings.data.0.deadline', '27/10/2026')
            ->where('findings.data.1.is_overdue', true)
            ->where('findings.data.1.acknowledged.by', $admin->name)
            ->where('findings.data.1.service.name', 'shop-api')
            ->where('findings.data.1.team', 'Commerce'));
});

it('marks the findings CISA KEV lists a CVE of', function () {
    actingAsAdmin();
    Finding::factory()->for($this->deployment)->create(['severity' => Severity::Critical, 'context' => ['known_exploited' => [['cve' => 'CVE-2026-109618', 'name' => 'PHP RCE', 'date_added' => '2026-10-26', 'due_date' => '2026-10-29', 'listed' => true]]]]);
    Finding::factory()->for($this->deployment)->create(['severity' => Severity::High]);

    get('/admin/findings')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('findings.data.0.known_exploited', ['CVE-2026-109618'])
            ->where('findings.data.1.known_exploited', null));
});

it('filters on a team, the read state and the findings of one card, as the links in Google Chat do', function () {
    actingAsAdmin();
    $booking = Deployment::factory()->for(Service::factory()->for(Project::factory()->for(Team::factory())))->create();
    $inCard = Finding::factory()->for($this->deployment)->create(['title' => 'Unread in card']);
    $readInCard = Finding::factory()->for($this->deployment)->create(['title' => 'Read', 'acknowledged_at' => now()]);
    Finding::factory()->for($this->deployment)->create(['title' => 'Other card']);
    $otherTeam = Finding::factory()->for($booking)->create(['title' => 'Other team']);

    get("/admin/findings?team={$this->commerce->id}&status=unread&ids={$inCard->id},{$readInCard->id},{$otherTeam->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->has('findings.data', 1)
            ->where('findings.data.0.title', 'Unread in card')
            ->where('table.ids', "{$inCard->id},{$readInCard->id},{$otherTeam->id}"));

    get('/admin/findings?status=read')->assertInertia(fn (Assert $page) => $page->has('findings.data', 1)->where('findings.data.0.title', 'Read'));
});

it('filters on a project and one of its services, the service selecting its project as the lockfile summary link does', function () {
    actingAsAdmin();
    $shop = $this->deployment->service->project;
    $shopWeb = Deployment::factory()->for(Service::factory()->for($shop)->state(['name' => 'shop-web']))->create();
    $cms = Deployment::factory()->for(Service::factory()->for(Project::factory()->for($this->commerce)->state(['name' => 'CMS'])))->create();
    Finding::factory()->for($this->deployment)->create(['title' => 'shop-api finding']);
    Finding::factory()->for($shopWeb)->create(['title' => 'shop-web finding']);
    Finding::factory()->for($cms)->create(['title' => 'cms finding']);

    get("/admin/findings?project={$shop->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->has('findings.data', 2)
            ->where('table.project', $shop->id)
            ->where('services', [['value' => $this->deployment->service_id, 'label' => 'shop-api'], ['value' => $shopWeb->service_id, 'label' => 'shop-web']]));

    get("/admin/findings?service={$shopWeb->service_id}")
        ->assertInertia(fn (Assert $page) => $page
            ->has('findings.data', 1)
            ->where('findings.data.0.title', 'shop-web finding')
            ->where('table.project', $shop->id)
            ->where('table.service', $shopWeb->service_id));

    get('/admin/findings')->assertInertia(fn (Assert $page) => $page->where('table.project', null)->where('services', []));
});

it('offers the projects of the team picked', function () {
    actingAsAdmin();
    Project::factory()->for(Team::factory())->create(['name' => 'Booking']);

    get("/admin/findings?team={$this->commerce->id}")
        ->assertInertia(fn (Assert $page) => $page->where('projects', [['value' => $this->deployment->service->project_id, 'label' => $this->deployment->service->project->name]]));

    get('/admin/findings')->assertInertia(fn (Assert $page) => $page->has('projects', 2));
});

it('shows titles in the admin language, keeping the stored one for Google Chat', function () {
    actingAsAdmin()->update(['locale' => Locale::Japanese]);
    Finding::factory()->for($this->deployment)->create(['rule' => FindingRule::DependencyVulnerable, 'severity' => Severity::Critical, 'title' => 'GHSA-8xf4-w7qw-pjjw trong firebase/php-jwt', 'context' => ['advisory' => 'GHSA-8xf4-w7qw-pjjw', 'package' => 'firebase/php-jwt']]);
    Finding::factory()->for($this->deployment)->create(['rule' => FindingRule::RuntimeOutdatedSecurity, 'severity' => Severity::High, 'title' => 'PHP 8.5 thiếu bản vá bảo mật, cần nâng lên 8.5.12', 'context' => ['technology' => 'PHP', 'branch' => '8.5', 'latest' => '8.5.12']]);
    $stored = Finding::factory()->for($this->deployment)->create(['rule' => FindingRule::RuntimeEol, 'severity' => Severity::Medium, 'title' => 'PHP 8.1 hết hỗ trợ', 'context' => null]);

    get('/admin/findings')->assertInertia(fn (Assert $page) => $page
        ->where('findings.data.0.title', 'firebase/php-jwt の GHSA-8xf4-w7qw-pjjw')
        ->where('findings.data.1.title', 'PHP 8.5 にセキュリティパッチ未適用、8.5.12 へ更新が必要')
        ->where('findings.data.2.title', 'PHP 8.1 hết hỗ trợ'));

    expect($stored->fresh()->title)->toBe('PHP 8.1 hết hỗ trợ');
});

/**
 * A finding of each rule that opens findings, the package one in a service of another team.
 *
 * @return array{package: Finding, outdated: Finding, eol: Finding}
 */
function createSearchableFindings(Deployment $deployment): array
{
    $booking = Deployment::factory()->for(Service::factory()->for(Project::factory()->for(Team::factory()->state(['name' => 'Travel']))->state(['name' => 'Booking']))->state(['name' => 'booking-web']))->create();

    return [
        'package' => Finding::factory()->for($booking)->create(['rule' => FindingRule::DependencyVulnerable, 'severity' => Severity::Critical, 'title' => 'GHSA-754f-8gm6-c4r2 trong symfony/http-kernel', 'context' => [
            'ecosystem' => 'composer', 'package' => 'symfony/http-kernel', 'version' => '7.3.1', 'fixed' => '7.3.4', 'advisory' => 'GHSA-754f-8gm6-c4r2', 'advisory_title' => 'Session fixation', 'aliases' => ['CVE-2026-24739'],
        ]]),
        'outdated' => Finding::factory()->for($deployment)->create(['rule' => FindingRule::RuntimeOutdatedSecurity, 'severity' => Severity::High, 'title' => 'PHP 8.5 thiếu bản vá bảo mật, cần nâng lên 8.5.12', 'context' => ['technology' => 'PHP', 'branch' => '8.5', 'latest' => '8.5.12']]),
        'eol' => Finding::factory()->for($deployment)->create(['rule' => FindingRule::RuntimeEol, 'severity' => Severity::Medium, 'title' => 'PHP 8.1 hết hỗ trợ', 'context' => ['technology' => 'PHP', 'branch' => '8.1']]),
    ];
}

/**
 * The rows the Findings page shows for this query string.
 *
 * @param  array<string, string>  $query
 * @return list<array<string, mixed>>
 */
function findingRows(array $query): array
{
    return get('/admin/findings?'.http_build_query($query))->assertOk()->inertiaProps('findings.data');
}

it('searches in the words a japanese admin reads, and in the title copied from Google Chat', function (string $search, array $titles) {
    actingAsAdmin()->update(['locale' => Locale::Japanese]);
    createSearchableFindings($this->deployment);

    $rows = findingRows(['search' => $search]);

    expect(array_column($rows, 'title'))->toBe($titles);
})->with([
    'title words' => ['サポート終了', ['PHP 8.1 はサポート終了']],
    'version and title words' => ['PHP 8.1 サポート終了', ['PHP 8.1 はサポート終了']],
    'full-width space' => ['PHP　セキュリティパッチ', ['PHP 8.5 にセキュリティパッチ未適用、8.5.12 へ更新が必要']],
    'rule label' => ['パッケージの脆弱性', ['symfony/http-kernel の GHSA-754f-8gm6-c4r2']],
    'stored title' => ['hết hỗ trợ', ['PHP 8.1 はサポート終了']],
]);

it('searches in the words a vietnamese admin reads', function (string $search, array $titles) {
    actingAsAdmin();
    createSearchableFindings($this->deployment);

    $rows = findingRows(['search' => $search]);

    expect(array_column($rows, 'title'))->toBe($titles);
})->with([
    'title words' => ['PHP hết hỗ trợ', ['PHP 8.1 hết hỗ trợ']],
    'rule label' => ['Runtime thiếu bản vá', ['PHP 8.5 thiếu bản vá bảo mật, cần nâng lên 8.5.12']],
    'japanese words' => ['サポート終了', []],
]);

it('searches the parts of a finding that read the same in every language', function (string $search, array $found, Locale $locale) {
    actingAsAdmin()->update(['locale' => $locale]);
    $findings = createSearchableFindings($this->deployment);

    $rows = findingRows(['search' => $search]);

    expect(array_column($rows, 'id'))->toBe(array_map(fn (string $key): int => $findings[$key]->id, $found));
})->with([
    'package' => ['http-kernel', ['package']],
    'cve alias' => ['cve-2026-24739', ['package']],
    'fixed version' => ['7.3.4', ['package']],
    'service' => ['shop-api', ['outdated', 'eol']],
    'project' => ['Booking', ['package']],
    'team and technology' => ['Commerce PHP', ['outdated', 'eol']],
    'words of different findings' => ['PHP http-kernel', []],
    'nothing' => ['wordpress', []],
])->with([Locale::Vietnamese, Locale::Japanese]);

it('sorts on the title the admin reads', function (Locale $locale, array $titles) {
    actingAsAdmin()->update(['locale' => $locale]);
    createSearchableFindings($this->deployment);

    $rows = findingRows(['sort' => 'title']);

    expect(array_column($rows, 'title'))->toBe($titles);
})->with([
    'vietnamese' => [Locale::Vietnamese, ['GHSA-754f-8gm6-c4r2 trong symfony/http-kernel', 'PHP 8.1 hết hỗ trợ', 'PHP 8.5 thiếu bản vá bảo mật, cần nâng lên 8.5.12']],
    'japanese' => [Locale::Japanese, ['PHP 8.1 はサポート終了', 'PHP 8.5 にセキュリティパッチ未適用、8.5.12 へ更新が必要', 'symfony/http-kernel の GHSA-754f-8gm6-c4r2']],
]);

it('shows times in the timezone of the admin\'s language, named, and the deadline as a day in the digest timezone', function (Locale $locale, array $expected) {
    actingAsAdmin()->update(['locale' => $locale]);
    Finding::factory()->for($this->deployment)->create(['first_seen_at' => '2026-10-23 18:00:00', 'sla_due_at' => '2026-10-27 16:30:00', 'acknowledged_at' => '2026-10-23 18:30:00']);

    get('/admin/findings')->assertInertia(fn (Assert $page) => $page
        ->where('findings.data.0.first_seen', $expected[0])
        ->where('findings.data.0.deadline', $expected[1])
        ->where('findings.data.0.acknowledged.at', $expected[2]));
})->with([
    'vietnamese' => [Locale::Vietnamese, ['24/10/2026', '27/10/2026', '24/10/2026 01:30 GMT+7']],
    'japanese' => [Locale::Japanese, ['2026/10/24', '2026/10/27', '2026/10/24 03:30 JST']],
]);

/**
 * What the page sends for these findings: each with the version it shows.
 *
 * @return list<array{id: int, version: int|null}>
 */
function shownFindings(Finding ...$findings): array
{
    return array_map(fn (Finding $finding): array => [
        'id' => $finding->id,
        'version' => $finding->events()->whereIn('type', AcknowledgeFindings::VERSION_EVENTS)->max('id'),
    ], $findings);
}

it('marks only the picked findings that are open and not read yet, and records who did', function () {
    $admin = actingAsAdmin();
    $picked = Finding::factory()->for($this->deployment)->create();
    $alreadyRead = Finding::factory()->for($this->deployment)->create(['acknowledged_at' => '2026-10-20 10:00:00']);
    $resolved = Finding::factory()->for($this->deployment)->create(['state' => FindingState::Resolved]);
    $notPicked = Finding::factory()->for($this->deployment)->create();

    post('/admin/findings/acknowledgements', ['findings' => shownFindings($picked, $alreadyRead, $resolved)])
        ->assertRedirect()
        ->assertInertiaFlash('toast.message', 'Đã đánh dấu 1 finding là đã đọc. Hệ thống ngừng nhắc hằng ngày.');

    expect($picked->refresh())
        ->acknowledged_at->toDateTimeString()->toBe('2026-10-24 10:00:00')
        ->acknowledged_by->toBe($admin->id)
        ->and($picked->events()->where('type', FindingEventType::Acknowledged)->sole()->data)->toEqual(['user_id' => $admin->id, 'user' => $admin->name])
        ->and($alreadyRead->refresh()->acknowledged_at->toDateTimeString())->toBe('2026-10-20 10:00:00')
        ->and($resolved->refresh()->acknowledged_at)->toBeNull()
        ->and($notPicked->refresh()->acknowledged_at)->toBeNull();
});

it('does not mark a finding that rose in severity or opened again since the page was loaded', function (FindingEventType $change) {
    actingAsAdmin();
    $finding = Finding::factory()->for($this->deployment)->create();
    $finding->events()->create(['type' => FindingEventType::Opened]);
    $shown = shownFindings($finding);
    $finding->events()->create(['type' => $change]);

    post('/admin/findings/acknowledgements', ['findings' => $shown])
        ->assertInertiaFlash('toast.type', 'error')
        ->assertInertiaFlash('toast.description', '1 finding đã thay đổi từ lúc mở trang (mức tăng hoặc mở lại) nên chưa được đánh dấu. Xem lại rồi đánh dấu.');

    expect($finding->refresh()->acknowledged_at)->toBeNull();
})->with([FindingEventType::Escalated, FindingEventType::Opened, FindingEventType::KnownExploited]);

it('goes back to the first page of the list', function () {
    actingAsAdmin();
    $finding = Finding::factory()->for($this->deployment)->create();

    post('/admin/findings/acknowledgements', ['findings' => shownFindings($finding)], ['referer' => "http://localhost/admin/findings?team={$this->commerce->id}&page=2"])
        ->assertRedirect("http://localhost/admin/findings?team={$this->commerce->id}");
});

it('asks which findings to mark', function () {
    actingAsAdmin();

    post('/admin/findings/acknowledgements', [])->assertSessionHasErrors('findings');
});

it('is only for admins', function () {
    $finding = Finding::factory()->for($this->deployment)->create();

    get('/admin/findings')->assertRedirect('/admin/login');

    actingAs(User::factory()->create());

    post('/admin/findings/acknowledgements', ['findings' => shownFindings($finding)]);
    expect($finding->refresh()->acknowledged_at)->toBeNull();
});
