<?php

use App\Actions\AssignNoticeToIncident;
use App\Actions\SendDailyDigest;
use App\Actions\SendFindingAlerts;
use App\Actions\SendPreNoticeAlerts;
use App\Enums\FindingRule;
use App\Enums\IncidentImpact;
use App\Enums\IncidentScope;
use App\Enums\OperatingSystem;
use App\Enums\Severity;
use App\Enums\Technology;
use App\Models\Advisory;
use App\Models\Finding;
use App\Models\Incident;
use App\Models\IncidentTeam;
use App\Models\IncidentTeamPatch;
use App\Models\Runtime;
use App\Models\RuntimeRelease;
use App\Models\Team;
use App\Services\IncidentTeamExposure;

beforeEach(function () {
    $this->travelTo('2026-10-20 21:00:00');
    fakeGoogleChat();
    $this->windowsTeam = Team::factory()->create(['name' => 'Windows', 'google_chat_webhook_url' => chatWebhook('WINDOWS')]);
    $this->linuxTeam = Team::factory()->create(['name' => 'Linux', 'google_chat_webhook_url' => chatWebhook('LINUX')]);
    $this->unknownTeam = Team::factory()->create(['name' => 'Unknown', 'google_chat_webhook_url' => chatWebhook('UNKNOWN')]);
    $this->windowsNotice = Advisory::factory()->preNotice(Technology::Php)->create([
        'title' => 'Updated PHP Windows builds for Brotli security fix',
        'aliases' => ['CVE-2026-111497'],
        'affected_branches' => ['8.4'],
        'platforms' => ['windows'],
        'platforms_evidence' => 'Only the Windows builds 8.x.y-1 are affected.',
        'published_at' => '2026-10-20 20:32:00',
    ]);
    app(AssignNoticeToIncident::class)->assignUnassigned();
});

/**
 * A PHP runtime of the team's service on a known branch, on a deployment running `$system`.
 */
function osRuntime(Team $team, string $service, string $version, ?OperatingSystem $system): Runtime
{
    $runtime = runtimeAt($team, $service, Technology::Php, $version);
    $runtime->update(['version' => $version, 'branch' => implode('.', array_slice(explode('.', $version), 0, 2))]);
    $runtime->deployment->update(['os' => $system]);

    return $runtime->refresh();
}

it('rules out a runtime on an operating system every pre-notice excludes, and only that one', function () {
    $windows = osRuntime($this->windowsTeam, 'shop-api', '8.4.27', OperatingSystem::Windows);
    $linux = osRuntime($this->linuxTeam, 'booking-api', '8.4.27', OperatingSystem::Linux);
    $unknown = osRuntime($this->unknownTeam, 'events-api', '8.4.27', null);
    $incident = $this->windowsNotice->refresh()->incident;

    expect($incident->scopeFor($windows))->toBe(IncidentScope::Confirmed)
        ->and($incident->scopeFor($linux))->toBe(IncidentScope::NotAffected)
        ->and($incident->excludedBy($linux))->toBe([OperatingSystem::Windows])
        ->and($incident->scopeFor($unknown))->toBe(IncidentScope::Confirmed)
        ->and($incident->excludedBy($unknown))->toBeNull()
        ->and(app(SendPreNoticeAlerts::class)->handle())->toBe(2)
        ->and(chatMessagesTo('WINDOWS'))->toHaveCount(1)
        ->and(chatMessagesTo('UNKNOWN'))->toHaveCount(1)
        ->and(chatMessagesTo('LINUX'))->toHaveCount(0);
});

it('keeps every operating system in scope once one pre-notice of the incident states no limit', function () {
    $linux = osRuntime($this->linuxTeam, 'booking-api', '8.4.27', OperatingSystem::Linux);
    Advisory::factory()->preNotice(Technology::Php)->create(['title' => 'PHP security advisory', 'aliases' => ['CVE-2026-111497'], 'published_at' => '2026-10-20 20:40:00']);
    app(AssignNoticeToIncident::class)->assignUnassigned();

    expect($this->windowsNotice->refresh()->incident->scopeFor($linux))->toBe(IncidentScope::Unknown)
        ->and(app(SendPreNoticeAlerts::class)->handle())->toBe(1)
        ->and(chatMessagesTo('LINUX'))->toHaveCount(1);
});

it('reads each operating system from the pre-notices that apply to it, never a mix of both', function () {
    Advisory::factory()->preNotice(Technology::Php)->create([
        'title' => 'PHP Linux packages security fix',
        'aliases' => ['CVE-2026-111497'],
        'affected_branches' => ['8.3'],
        'platforms' => ['linux'],
        'platforms_evidence' => 'Only the Linux packages of 8.3 are affected.',
        'published_at' => '2026-10-20 20:40:00',
    ]);
    app(AssignNoticeToIncident::class)->assignUnassigned();
    $incident = $this->windowsNotice->refresh()->incident;

    expect(Incident::count())->toBe(1);

    expect($incident->scopeFor(osRuntime($this->linuxTeam, 'linux-84', '8.4.20', OperatingSystem::Linux)))->toBe(IncidentScope::Unknown)
        ->and($incident->scopeFor(osRuntime($this->linuxTeam, 'linux-83', '8.3.20', OperatingSystem::Linux)))->toBe(IncidentScope::Confirmed)
        ->and($incident->scopeFor(osRuntime($this->windowsTeam, 'windows-84', '8.4.20', OperatingSystem::Windows)))->toBe(IncidentScope::Confirmed)
        ->and($incident->scopeFor(osRuntime($this->windowsTeam, 'windows-83', '8.3.20', OperatingSystem::Windows)))->toBe(IncidentScope::Unknown)
        ->and($incident->scopeFor(osRuntime($this->windowsTeam, 'mac-84', '8.4.20', OperatingSystem::MacOs)))->toBe(IncidentScope::NotAffected)
        ->and($incident->scopeFor(osRuntime($this->unknownTeam, 'unknown-84', '8.4.20', null)))->toBe(IncidentScope::Confirmed);
});

it('alerts a service once its operating system changes to one the incident concerns, at the same version', function () {
    $runtime = osRuntime($this->linuxTeam, 'booking-api', '8.4.27', OperatingSystem::Linux);

    expect(app(SendPreNoticeAlerts::class)->handle())->toBe(0);

    $this->travel(15)->minutes();
    $runtime->deployment->update(['os' => OperatingSystem::Windows]);

    expect(app(SendPreNoticeAlerts::class)->handle())->toBe(1)
        ->and(chatJson(chatMessagesTo('LINUX')->sole()))->toContain('booking-api', 'PHP 8.4.27');
});

it('ends a "Không" once a service moves to an operating system the incident concerns', function () {
    osRuntime($this->windowsTeam, 'shop-api', '8.4.27', OperatingSystem::Windows);
    $linux = osRuntime($this->windowsTeam, 'shop-cli', '8.4.27', OperatingSystem::Linux);
    app(SendPreNoticeAlerts::class)->handle();
    $incident = $this->windowsNotice->refresh()->incident;
    assessIncident($incident, $this->windowsTeam, IncidentImpact::No);
    $before = IncidentTeamExposure::for($incident, $this->windowsTeam);

    expect(collect($before->services)->firstWhere('service', 'shop-cli'))->scope->toBe(IncidentScope::NotAffected->value)->os->toBe('linux')
        ->and(IncidentTeam::isRuledOutFor($incident, $this->windowsTeam->id))->toBeTrue();

    $linux->deployment->update(['os' => OperatingSystem::MacOs]);

    expect(IncidentTeam::isRuledOutFor($incident, $this->windowsTeam->id))->toBeTrue();

    $linux->deployment->update(['os' => OperatingSystem::Windows]);

    expect(IncidentTeam::isRuledOutFor($incident, $this->windowsTeam->id))->toBeFalse()
        ->and(IncidentTeamExposure::for($incident, $this->windowsTeam)->hash())->not->toBe($before->hash());
});

it('confirms no fix from a pre-notice limited to an operating system, so it covers no finding of another one and holds none', function () {
    $this->travelTo('2026-10-21 15:00:00');
    $this->windowsNotice->update(['patch_versions' => ['8.4.27']]);
    RuntimeRelease::factory()->security()->create(['branch' => '8.4', 'version' => '8.4.27']);
    osRuntime($this->windowsTeam, 'shop-api', '8.4.26', OperatingSystem::Windows);
    $linux = osRuntime($this->windowsTeam, 'shop-cli', '8.4.26', OperatingSystem::Linux);
    $finding = Finding::factory()->for($linux->deployment)->create([
        'rule' => FindingRule::RuntimeOutdatedSecurity,
        'runtime_id' => $linux->id,
        'group_key' => 'runtime_outdated_security:php:8.4.27',
        'context' => ['latest' => '8.4.27'],
    ]);
    $incident = $this->windowsNotice->refresh()->incident;

    expect($incident->confirmedPatches())->toBe([])
        ->and(app(SendPreNoticeAlerts::class)->handle())->toBe(1)
        ->and(chatMessagesTo('WINDOWS')->sole()['text'])->toBe('[Critical] Updated PHP Windows builds for Brotli security fix')
        ->and(IncidentTeamPatch::count())->toBe(0)
        ->and($finding->refresh()->context)->not->toHaveKey('covered_by_patch_id')
        ->and(app(SendFindingAlerts::class)->handle())->toBe(1);
});

it('never takes a Windows rebuild for the fix of the branch, even when its version reads like a release', function () {
    $this->windowsNotice->update(['patch_versions' => ['8.4.27'], 'unaffected_branches' => []]);
    RuntimeRelease::factory()->security()->create(['branch' => '8.4', 'version' => '8.4.27']);
    $windows = osRuntime($this->windowsTeam, 'shop-api', '8.4.27', OperatingSystem::Windows);
    $incident = $this->windowsNotice->refresh()->incident;

    expect($incident->confirmedPatches())->toBe([])
        ->and(IncidentTeamExposure::for($incident, $this->windowsTeam)->services[0]['patch'])->toBeNull()
        ->and(IncidentTeamExposure::waitingBranches($incident, collect([$windows]), $incident->confirmedPatches()))->toBe(['8.4']);
});

it('leaves an incident limited to another operating system out of the daily digest', function () {
    $this->windowsNotice->update(['severity' => Severity::Medium]);
    osRuntime($this->linuxTeam, 'booking-api', '8.4.27', OperatingSystem::Linux);
    osRuntime($this->windowsTeam, 'shop-api', '8.4.27', OperatingSystem::Windows);
    $this->travelTo('2026-10-21 08:00:00');

    expect(app(SendDailyDigest::class)->digestFor($this->windowsTeam)['pre_notices']->pluck('title')->all())->toBe(['Updated PHP Windows builds for Brotli security fix'])
        ->and(app(SendDailyDigest::class)->digestFor($this->linuxTeam)['pre_notices'])->toBeEmpty();
});

it('moves the incident back to the digest when a pre-notice gains or loses its limit', function () {
    $incident = $this->windowsNotice->refresh()->incident;
    $this->travel(1)->hour();

    $this->windowsNotice->update(['platforms' => null, 'platforms_evidence' => null]);

    expect($incident->refresh())->platform_scopes->toBeNull()
        ->changed_at->toDateTimeString()->toBe(now()->toDateTimeString());
});

it('shows the incident page a service on another operating system as not affected, with the reason', function () {
    osRuntime($this->windowsTeam, 'shop-api', '8.4.27', OperatingSystem::Windows);
    osRuntime($this->windowsTeam, 'shop-cli', '8.4.27', OperatingSystem::Linux);
    app(SendPreNoticeAlerts::class)->handle();
    $incident = Incident::sole();
    actingAsAdmin();

    $this->get("/admin/incidents/{$incident->id}")->assertInertia(fn ($page) => $page
        ->where('teams.0.services', fn ($services) => collect($services)->firstWhere('name', 'shop-cli')['scope'] === 'đã xác nhận không ảnh hưởng (chỉ Windows)'
            && collect($services)->firstWhere('name', 'shop-cli')['is_not_affected'] === true
            && collect($services)->firstWhere('name', 'shop-api')['scope'] === IncidentScope::Confirmed->getLabel()));
});
