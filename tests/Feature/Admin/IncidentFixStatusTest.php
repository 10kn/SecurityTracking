<?php

use App\Actions\SendPreNoticeAlerts;
use App\Enums\Severity;
use App\Enums\Technology;
use App\Models\Advisory;
use App\Models\Incident;
use App\Models\Runtime;
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
            ['branch' => '8.1', 'version' => null, 'is_affected' => false, 'is_eol' => false],
            ['branch' => '8.3', 'version' => '8.3.36', 'is_affected' => false, 'is_eol' => false],
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
            ['branch' => '8.1', 'version' => null, 'is_affected' => false, 'is_eol' => true],
            ['branch' => '8.3', 'version' => null, 'is_affected' => false, 'is_eol' => false],
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
            ['branch' => '8.1', 'version' => null, 'is_affected' => false, 'is_eol' => false],
            ['branch' => '8.3', 'version' => null, 'is_affected' => false, 'is_eol' => false],
        ])
        ->where('incident.teams_to_acknowledge_count', 0));
});

it('tells a waiting branch the vendor named as affected from one it did not name', function () {
    actingAsAdmin();
    $this->preNotice->update(['affected_branches' => ['8.3']]);
    $this->incident->refreshFromNotices();

    get("/admin/incidents/{$this->incident->id}")->assertInertia(fn (Assert $page) => $page
        ->where('incident.fixes', [
            ['branch' => '8.1', 'version' => null, 'is_affected' => false, 'is_eol' => false],
            ['branch' => '8.3', 'version' => null, 'is_affected' => true, 'is_eol' => false],
        ]));
});

it('stops waiting on a branch whose service runs a security release from the day before the incident on, or a later version', function (string $releasedAt, string $running, array $waiting) {
    actingAsAdmin();
    RuntimeRelease::factory()->security()->create(['branch' => '8.3', 'version' => '8.3.36', 'released_at' => $releasedAt]);
    Runtime::where('branch', '8.3')->update(['raw_version' => $running, 'version' => $running]);

    get("/admin/incidents/{$this->incident->id}")->assertInertia(fn (Assert $page) => $page
        ->where('incident.fixes', array_map(fn (string $branch): array => ['branch' => $branch, 'version' => null, 'is_affected' => false, 'is_eol' => false], $waiting)));
})->with([
    'the release itself' => ['2026-10-21', '8.3.36', ['8.1']],
    'a later version' => ['2026-10-19', '8.3.37', ['8.1']],
    'a release from before the incident' => ['2026-10-18', '8.3.36', ['8.1', '8.3']],
    'an older version than the release' => ['2026-10-21', '8.3.35', ['8.1', '8.3']],
]);

it('says why no team was told about an incident whose pre-notices can no longer alert', function (array $attributes, ?string $reason) {
    actingAsAdmin();
    $incident = Incident::factory()->create(['technology' => Technology::Php, 'title' => 'PHP 8.4.26 Released!', 'first_published_at' => $attributes['published_at']]);
    Advisory::factory()->preNotice(Technology::Php)->create(['incident_id' => $incident->id, 'title' => 'PHP 8.4.26 Released!', ...$attributes]);

    get("/admin/incidents/{$incident->id}")->assertInertia(fn (Assert $page) => $page->where('incident.not_told_reason', $reason));
})->with([
    'history from the first poll' => [['published_at' => '2026-10-19 10:00:00', 'is_backfill' => true], 'Tin có sẵn trên nguồn từ trước khi hệ thống bắt đầu theo dõi nguồn này, nên không được báo cho team.'],
    'published too long ago' => [['published_at' => '2026-09-24 10:00:00', 'is_backfill' => false], 'Tin đã đăng hơn 14 ngày, nên không còn được báo cho team.'],
    'recent enough to alert' => [['published_at' => '2026-10-19 10:00:00', 'is_backfill' => false], null],
]);

it('gives no reason once a team was told', function () {
    actingAsAdmin();
    $this->preNotice->update(['is_backfill' => true]);

    get("/admin/incidents/{$this->incident->id}")->assertInertia(fn (Assert $page) => $page->where('incident.not_told_reason', null));
});
