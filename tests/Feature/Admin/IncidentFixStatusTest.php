<?php

use App\Actions\SendPreNoticeAlerts;
use App\Enums\Severity;
use App\Enums\Technology;
use App\Models\Advisory;
use App\Models\Incident;
use App\Models\RuntimeBranch;
use App\Models\RuntimeRelease;
use App\Models\Team;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\get;

beforeEach(function () {
    $this->travelTo('2026-10-20 21:00:00');
    fakeGoogleChat();
    $this->commerce = Team::factory()->create(['name' => 'Commerce', 'google_chat_webhook_url' => chatWebhook('COMMERCE')]);
    $this->preNotice = Advisory::factory()->preNotice(Technology::Php)->create([
        'title' => 'Upcoming Critical PHP Security Release',
        'severity' => Severity::High,
        'aliases' => ['CVE-2026-1001'],
        'published_at' => '2026-10-20 20:32:00',
    ]);
    fixStatusRuntime($this->commerce, 'shop-api', '8.3.35');
    fixStatusRuntime($this->commerce, 'legacy-api', '8.1.20');
    app(SendPreNoticeAlerts::class)->handle();
    $this->incident = $this->preNotice->refresh()->incident;
});

/**
 * A PHP runtime of the team's service on a known branch.
 */
function fixStatusRuntime(Team $team, string $service, string $version): void
{
    runtimeAt($team, $service, Technology::Php, $version)->update(['version' => $version, 'branch' => implode('.', array_slice(explode('.', $version), 0, 2))]);
}

it('says the vendor released a fix, never that the incident is solved', function () {
    actingAsAdmin();
    $this->preNotice->update(['patch_versions' => ['8.3.36', '8.1.31']]);
    RuntimeRelease::factory()->security()->create(['branch' => '8.3', 'version' => '8.3.36']);
    RuntimeRelease::factory()->security()->create(['branch' => '8.1', 'version' => '8.1.31']);

    get('/admin/incidents')->assertInertia(fn (Assert $page) => $page
        ->where('incidents.data.0.fix_status', ['label' => 'Hãng đã có bản vá', 'tone' => 'neutral']));
});

it('says a fix is partial while a branch a team runs waits', function () {
    actingAsAdmin();
    $this->preNotice->update(['patch_versions' => ['8.3.36']]);
    RuntimeRelease::factory()->security()->create(['branch' => '8.3', 'version' => '8.3.36']);

    get('/admin/incidents')->assertInertia(fn (Assert $page) => $page
        ->where('incidents.data.0.fix_status', ['label' => 'Hãng có bản vá một phần', 'tone' => 'warning'])
        ->where('incidents.data.0.fixes', [
            ['branch' => '8.1', 'version' => null, 'is_eol' => false],
            ['branch' => '8.3', 'version' => '8.3.36', 'is_eol' => false],
        ]));
});

it('says no confirmed fix is known, rather than that there is none, when no branch has one', function (?array $patchVersions) {
    actingAsAdmin();
    $this->preNotice->update(['patch_versions' => $patchVersions]);

    get('/admin/incidents')->assertInertia(fn (Assert $page) => $page
        ->where('incidents.data.0.fix_status', ['label' => 'Chưa có thông tin bản vá xác nhận', 'tone' => 'unknown']));
})->with([
    'no version named' => [null],
    'a version the catalog does not list as a security release' => [['8.3.36']],
]);

it('says no confirmed fix is known for an incident without a branch to show', function () {
    actingAsAdmin();
    $other = Incident::factory()->create(['technology' => Technology::Ruby, 'title' => 'Ruby news', 'first_published_at' => now()]);

    get('/admin/incidents?technology=ruby')->assertInertia(fn (Assert $page) => $page
        ->where('incidents.data.0.id', $other->id)
        ->where('incidents.data.0.fixes', [])
        ->where('incidents.data.0.fix_status.label', 'Chưa có thông tin bản vá xác nhận'));
});

it('marks a waiting branch the catalog says reached end of life, and infers nothing for a branch it does not list', function () {
    actingAsAdmin();
    RuntimeBranch::factory()->eol()->create(['technology' => Technology::Php, 'branch' => '8.1']);
    RuntimeBranch::factory()->eol()->create(['technology' => Technology::Nodejs, 'branch' => '8.3']);

    get("/admin/incidents/{$this->incident->id}")->assertInertia(fn (Assert $page) => $page
        ->where('incident.fixes', [
            ['branch' => '8.1', 'version' => null, 'is_eol' => true],
            ['branch' => '8.3', 'version' => null, 'is_eol' => false],
        ]));
});

it('marks an incident outside the reminder window apart from its fix status, and keeps its waiting branches', function () {
    actingAsAdmin();

    get("/admin/incidents/{$this->incident->id}")->assertInertia(fn (Assert $page) => $page->where('incident.is_outside_reminder_window', false));

    $this->travel(config('psm.incidents.awaiting_patch_days') + 1)->days();

    get("/admin/incidents/{$this->incident->id}")->assertInertia(fn (Assert $page) => $page
        ->where('incident.is_outside_reminder_window', true)
        ->where('incident.fix_status.label', 'Chưa có thông tin bản vá xác nhận')
        ->where('incident.fixes', [
            ['branch' => '8.1', 'version' => null, 'is_eol' => false],
            ['branch' => '8.3', 'version' => null, 'is_eol' => false],
        ])
        ->where('incident.teams_to_acknowledge_count', 0));
});
