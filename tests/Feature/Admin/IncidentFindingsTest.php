<?php

use App\Enums\FindingRule;
use App\Enums\Severity;
use App\Enums\Technology;
use App\Models\Advisory;
use App\Models\Deployment;
use App\Models\Finding;
use App\Models\Incident;
use App\Models\Project;
use App\Models\Service;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\get;

beforeEach(function () {
    $this->travelTo('2026-10-20 21:00:00');
    $this->incident = Incident::factory()->create(['technology' => Technology::Php, 'title' => 'PHP 8.5.12 released', 'first_published_at' => now()]);
    $this->notice = Advisory::factory()->preNotice(Technology::Php)->create(['title' => 'PHP 8.5.12 released', 'severity' => Severity::High, 'published_at' => now(), 'incident_id' => $this->incident->id]);
    $this->team = Team::factory()->create(['name' => 'WEC']);
});

/**
 * A "thiếu bản vá" finding of a service, listing the fixes of the given incidents and pre-notices as its last
 * evaluation wrote them.
 *
 * @param  list<array{incident_id: int, notice_id: int}>  $relatedPatches
 * @param  array<string, mixed>  $attributes
 */
function outdatedFinding(Team $team, string $service, array $relatedPatches, array $attributes = []): Finding
{
    $deployment = Deployment::factory()->for(Service::factory()->for(Project::factory()->for($team))->state(['name' => $service, 'slug' => $service]))->create();

    return Finding::factory()->for($deployment)->create([
        'rule' => FindingRule::RuntimeOutdatedSecurity,
        'title' => 'PHP 8.5 thiếu bản vá bảo mật, cần nâng lên 8.5.12',
        'context' => [
            'technology' => 'PHP',
            'version' => '8.5.10',
            'branch' => '8.5',
            'latest' => '8.5.12',
            'related_patches' => array_map(fn (array $patch): array => [...$patch, 'url' => null, 'fixed_version' => '8.5.12', 'cves' => []], $relatedPatches),
        ],
        ...$attributes,
    ]);
}

it('lists the findings that name the incident, open unread first, with the versions of their last evaluation', function () {
    actingAsAdmin();
    $resolved = outdatedFinding($this->team, 'cms', [['incident_id' => $this->incident->id, 'notice_id' => $this->notice->id]], ['state' => 'resolved', 'resolved_at' => now()]);
    $read = outdatedFinding($this->team, 'wecsy', [['incident_id' => $this->incident->id, 'notice_id' => $this->notice->id]], ['acknowledged_at' => now(), 'acknowledged_by' => User::factory()->admin()->create()->id]);
    $unread = outdatedFinding($this->team, 'shop', [['incident_id' => $this->incident->id, 'notice_id' => $this->notice->id]]);

    get("/admin/incidents/{$this->incident->id}")->assertInertia(fn (Assert $page) => $page
        ->where('findings.total', 3)
        ->where('findings.open_ids', [$unread->id, $read->id])
        ->where('findings.items', fn ($items): bool => collect($items)->pluck('id')->all() === [$unread->id, $read->id, $resolved->id])
        ->where('findings.items.0.service', 'shop')
        ->where('findings.items.0.team', 'WEC')
        ->where('findings.items.0.state', ['label' => 'Mở · Chưa đọc', 'tone' => 'warning'])
        ->where('findings.items.0.recorded_version', '8.5.10')
        ->where('findings.items.0.recorded_at', '21/10/2026 04:00 GMT+7')
        ->where('findings.items.0.target_version', '8.5.12')
        ->where('findings.items.0.incident_fix', '8.5.12')
        ->where('findings.items.0.resolved', null)
        ->where('findings.items.1.state.label', 'Mở · Đã đọc')
        ->where('findings.items.2.state', ['label' => 'Đã giải quyết', 'tone' => 'neutral'])
        ->where('findings.items.2.resolved', '21/10/2026 04:00 GMT+7'));
});

it('lists a finding about one of its pre-notices', function () {
    actingAsAdmin();
    $deployment = Deployment::factory()->for(Service::factory()->for(Project::factory()->for($this->team)))->create();
    $finding = Finding::factory()->for($deployment)->create(['rule' => FindingRule::PreNotice, 'advisory_id' => $this->notice->id, 'context' => []]);

    get("/admin/incidents/{$this->incident->id}")->assertInertia(fn (Assert $page) => $page
        ->where('findings.total', 1)
        ->where('findings.items.0.id', $finding->id)
        ->where('findings.items.0.recorded_version', null)
        ->where('findings.items.0.target_version', null));
});

it('never matches a finding by technology or version alone', function () {
    actingAsAdmin();
    $other = Incident::factory()->create(['technology' => Technology::Php, 'title' => 'Other PHP incident']);
    $otherNotice = Advisory::factory()->preNotice(Technology::Php)->create(['incident_id' => $other->id]);
    outdatedFinding($this->team, 'cms', []);
    outdatedFinding($this->team, 'wecsy', [['incident_id' => $other->id, 'notice_id' => $otherNotice->id]]);

    get("/admin/incidents/{$this->incident->id}")->assertInertia(fn (Assert $page) => $page
        ->where('findings.total', 0)
        ->where('findings.items', [])
        ->where('findings.open_ids', []));
});

it('follows a pre-notice merged in since the finding was evaluated', function () {
    actingAsAdmin();
    $merged = Incident::factory()->create(['technology' => Technology::Php, 'merged_into_id' => $this->incident->id]);
    $movedNotice = Advisory::factory()->preNotice(Technology::Php)->create(['incident_id' => $this->incident->id]);
    $deletedNoticeId = Advisory::max('id') + 100;
    $byNotice = outdatedFinding($this->team, 'cms', [['incident_id' => $merged->id, 'notice_id' => $movedNotice->id]]);
    $byIncident = outdatedFinding($this->team, 'wecsy', [['incident_id' => $merged->id, 'notice_id' => $deletedNoticeId]]);

    get("/admin/incidents/{$this->incident->id}")->assertInertia(fn (Assert $page) => $page
        ->where('findings.items', fn ($items): bool => collect($items)->pluck('id')->sort()->values()->all() === [$byNotice->id, $byIncident->id]));
});

it('leaves out a finding whose pre-notice no longer belongs to any incident', function () {
    actingAsAdmin();
    $orphan = Advisory::factory()->preNotice(Technology::Php)->create(['incident_id' => null]);
    outdatedFinding($this->team, 'cms', [['incident_id' => $this->incident->id, 'notice_id' => $orphan->id]]);

    get("/admin/incidents/{$this->incident->id}")->assertInertia(fn (Assert $page) => $page->where('findings.total', 0));
});

it('follows a pre-notice split off since the finding was evaluated to its new incident', function () {
    actingAsAdmin();
    $split = Incident::factory()->create(['technology' => Technology::Php, 'title' => 'Split off']);
    $splitNotice = Advisory::factory()->preNotice(Technology::Php)->create(['incident_id' => $split->id]);
    $finding = outdatedFinding($this->team, 'cms', [['incident_id' => $this->incident->id, 'notice_id' => $splitNotice->id]]);

    get("/admin/incidents/{$this->incident->id}")->assertInertia(fn (Assert $page) => $page->where('findings.total', 0));
    get("/admin/incidents/{$split->id}")->assertInertia(fn (Assert $page) => $page->where('findings.total', 1)->where('findings.items.0.id', $finding->id));
});

it('sends at most 50 findings, with how many there are', function () {
    actingAsAdmin();

    foreach (range(1, 51) as $index) {
        outdatedFinding($this->team, "service-{$index}", [['incident_id' => $this->incident->id, 'notice_id' => $this->notice->id]]);
    }

    get("/admin/incidents/{$this->incident->id}")->assertInertia(fn (Assert $page) => $page
        ->where('findings.total', 51)
        ->has('findings.items', 50)
        ->has('findings.open_ids', 51));
});
