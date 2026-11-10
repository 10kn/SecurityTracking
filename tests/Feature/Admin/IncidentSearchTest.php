<?php

use App\Actions\SendPreNoticeAlerts;
use App\Enums\IncidentImpact;
use App\Enums\Severity;
use App\Enums\Technology;
use App\Models\Advisory;
use App\Models\Incident;
use App\Models\Runtime;
use App\Models\Team;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\get;

beforeEach(function () {
    $this->travelTo('2026-10-20 21:00:00');
    fakeGoogleChat();
    actingAsAdmin();
    $this->commerce = Team::factory()->create(['name' => 'Commerce', 'google_chat_webhook_url' => chatWebhook('COMMERCE')]);
    $this->booking = Team::factory()->create(['name' => 'Booking', 'google_chat_webhook_url' => chatWebhook('BOOKING')]);
    $this->preNotice = Advisory::factory()->preNotice(Technology::Php)->create([
        'title' => 'Upcoming Critical PHP Security Release',
        'severity' => Severity::High,
        'aliases' => ['CVE-2026-1001'],
        'url' => 'https://www.php.net/archive/2026.php#2026-10-20-1',
        'published_at' => '2026-10-20 20:32:00',
    ]);
    runtimeAt($this->commerce, 'shop-api', Technology::Php, '8.3.35')->update(['version' => '8.3.35', 'branch' => '8.3']);
    runtimeAt($this->booking, 'booking-api', Technology::Php, '8.4.26')->update(['version' => '8.4.26', 'branch' => '8.4']);
    app(SendPreNoticeAlerts::class)->handle();
    $this->incident = $this->preNotice->refresh()->incident;
});

/**
 * The incidents list searched for the text, with the other filters of the query string.
 *
 * @param  array<string, string|int>  $filters
 */
function searchIncidents(string $search, array $filters = []): TestResponse
{
    return get('/admin/incidents?'.http_build_query(['search' => $search, ...$filters]));
}

describe('the search', function () {
    beforeEach(function () {
        Advisory::factory()->preNotice(Technology::Php)->create([
            'incident_id' => $this->incident->id,
            'title' => 'PHP 8.3.36 Released',
            'aliases' => ['GHSA-w8hw-w9fx-j79w', 'CVE-2026-1001'],
            'resolved_cve_ids' => ['GHSA-w8hw-w9fx-j79w' => ['cves' => ['CVE-2026-109618'], 'source' => 'github'], 'GHSA-gone-gone-gone' => ['cves' => ['CVE-2026-109650'], 'source' => 'github']],
            'known_exploited' => [['cve' => 'CVE-2026-109700', 'name' => 'PHP Remote Code Execution', 'date_added' => '2026-10-20', 'due_date' => null, 'listed' => true]],
            'published_at' => '2026-10-20 20:40:00',
        ]);
        Incident::factory()->create(['technology' => Technology::Nodejs, 'title' => 'Node.js security releases']);
        Incident::whereKey($this->incident->id)->update(['title' => 'PHP security releases']);
    });

    it('finds an incident by the title or a CVE / GHSA id of one of its pre-notices, once, ignoring case and spaces around', function (string $search) {
        searchIncidents($search)->assertInertia(fn (Assert $page) => $page
            ->has('incidents.data', 1)
            ->where('incidents.data.0.id', $this->incident->id)
            ->where('incidents.meta.total', 1));
    })->with([
        'its own title' => 'php security releases',
        'a pre-notice title' => 'upcoming critical',
        'an alias of two pre-notices' => 'CVE-2026-1001',
        'a GHSA alias' => '  ghsa-w8hw-w9fx-j79w ',
        'a CVE resolved for a GHSA id' => 'cve-2026-109618',
        'a CVE CISA KEV lists' => 'CVE-2026-109700',
    ]);

    it('looks at the CVE id of a KEV entry and the GHSA ids still listed only, and finds nothing for text no incident has', function (string $search) {
        searchIncidents($search)->assertInertia(fn (Assert $page) => $page->has('incidents.data', 0)->where('incidents.meta.total', 0)->where('table.search', $search));
    })->with(['Remote Code Execution', 'listed', 'github', 'cves', 'GHSA-gone', 'CVE-2026-109650', 'CVE-2099-0001']);

    it('keeps the technology, team and dismissed filters', function () {
        Team::factory()->create(['name' => 'Payments']);
        $dismissed = Incident::factory()->create(['title' => 'PHP Conference']);
        Advisory::factory()->preNotice(Technology::Php)->create(['incident_id' => $dismissed->id, 'title' => 'PHP Conference 2026', 'aliases' => ['CVE-2026-1001'], 'is_dismissed' => true]);

        searchIncidents('CVE-2026-1001')->assertInertia(fn (Assert $page) => $page->has('incidents.data', 1)->where('incidents.data.0.id', $this->incident->id));
        searchIncidents('CVE-2026-1001', ['technology' => 'nodejs'])->assertInertia(fn (Assert $page) => $page->has('incidents.data', 0));
        searchIncidents('CVE-2026-1001', ['team' => $this->commerce->id])->assertInertia(fn (Assert $page) => $page->has('incidents.data', 1)->where('incidents.data.0.id', $this->incident->id));
        searchIncidents('CVE-2026-1001', ['team' => Team::where('name', 'Payments')->value('id')])->assertInertia(fn (Assert $page) => $page->has('incidents.data', 0));
        searchIncidents('CVE-2026-1001', ['dismissed' => 1])->assertInertia(fn (Assert $page) => $page->has('incidents.data', 1)->where('incidents.data.0.id', $dismissed->id));
    });
});

describe('the "Chưa rõ" filter', function () {
    it('lists the incidents marked as read with "Chưa rõ" while that holds, however old, and keeps the filter in the page links', function () {
        $old = Incident::factory()->create(['title' => 'Old PHP incident', 'first_published_at' => now()->subDays(90)]);
        $affected = Incident::factory()->create(['title' => 'Affected PHP incident']);
        assessIncident($this->incident, $this->commerce, IncidentImpact::Unknown);
        assessIncident($old, $this->booking, IncidentImpact::Unknown);
        assessIncident($affected, $this->commerce, IncidentImpact::Yes);

        get('/admin/incidents?unknown=1')->assertInertia(fn (Assert $page) => $page
            ->where('incidents.data', fn ($incidents) => collect($incidents)->pluck('id')->all() === [$this->incident->id, $old->id])
            ->where('incidents.meta.total', 2)
            ->where('incidents.meta.links.1.url', fn (string $url) => str_contains($url, 'unknown=1'))
            ->where('table.unknown', 1));
        get('/admin/incidents')->assertInertia(fn (Assert $page) => $page->has('incidents.data', 3)->where('table.unknown', null));

        $this->preNotice->update(['severity' => Severity::Critical]);

        get('/admin/incidents?unknown=1')->assertInertia(fn (Assert $page) => $page->has('incidents.data', 1)->where('incidents.data.0.id', $old->id));
    });

    it('counts only the filtered team', function () {
        assessIncident($this->incident, $this->commerce, IncidentImpact::Unknown);
        assessIncident($this->incident, $this->booking, IncidentImpact::Yes);

        get("/admin/incidents?unknown=1&team={$this->commerce->id}")->assertInertia(fn (Assert $page) => $page->has('incidents.data', 1)->where('incidents.data.0.id', $this->incident->id));
        get("/admin/incidents?unknown=1&team={$this->booking->id}")->assertInertia(fn (Assert $page) => $page->has('incidents.data', 0));
    });

    it('leaves out a team told about the incident that no longer runs its technology', function () {
        assessIncident($this->incident, $this->commerce, IncidentImpact::Unknown);
        Runtime::whereHas('deployment.service', fn ($query) => $query->where('name', 'shop-api'))->delete();

        get('/admin/incidents?unknown=1')->assertInertia(fn (Assert $page) => $page->has('incidents.data', 0));
    });

    it('goes with the search and what needs an admin', function () {
        $other = Incident::factory()->create(['title' => 'Another PHP incident']);
        assessIncident($this->incident, $this->commerce, IncidentImpact::Unknown);
        assessIncident($other, $this->commerce, IncidentImpact::Unknown);

        searchIncidents('CVE-2026-1001', ['unknown' => 1])->assertInertia(fn (Assert $page) => $page->has('incidents.data', 1)->where('incidents.data.0.id', $this->incident->id));
        get('/admin/incidents?unknown=1&attention=1')->assertInertia(fn (Assert $page) => $page->has('incidents.data', 1)->where('incidents.data.0.id', $this->incident->id));

        assessIncident($this->incident, $this->booking, IncidentImpact::Unknown);

        get('/admin/incidents?unknown=1&attention=1')->assertInertia(fn (Assert $page) => $page->has('incidents.data', 0));
    });

    it('reads the list in a number of queries that does not grow with the incidents', function () {
        assessIncident($this->incident, $this->commerce, IncidentImpact::Unknown);
        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });

        get('/admin/incidents?unknown=1')->assertOk();
        $before = $count;

        foreach (range(1, 3) as $index) {
            assessIncident(Incident::factory()->create(['title' => "PHP incident {$index}"]), $this->booking, IncidentImpact::Unknown);
        }

        $count = 0;
        get('/admin/incidents?unknown=1')->assertInertia(fn (Assert $page) => $page->has('incidents.data', 4));

        expect($count)->toBe($before);
    });
});
