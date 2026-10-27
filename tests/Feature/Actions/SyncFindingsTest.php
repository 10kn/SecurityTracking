<?php

use App\Actions\SyncFindings;
use App\Enums\FindingEventType;
use App\Enums\FindingRule;
use App\Enums\FindingState;
use App\Enums\Severity;
use App\Enums\Technology;
use App\Models\Deployment;
use App\Models\Finding;
use App\Models\Runtime;
use App\Models\User;
use App\Services\FindingData;
use App\Services\Kev\KnownExploitation;
use App\Services\RuntimeFindings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

function eolFindingData(Deployment $deployment, string $branch = '8.1', Severity $severity = Severity::High): FindingData
{
    return new FindingData(
        fingerprint: FindingData::fingerprint(FindingRule::RuntimeEol, $deployment->id, 'technology:php', $branch),
        rule: FindingRule::RuntimeEol,
        severity: $severity,
        title: "PHP {$branch} hết hỗ trợ",
        groupKey: "runtime_eol:1:{$branch}",
    );
}

function syncEol(Deployment $deployment, array $findings, ?Closure $keepOpen = null): void
{
    app(SyncFindings::class)->handle($deployment, [FindingRule::RuntimeEol], $findings, $keepOpen);
}

it('opens a finding for the team that owns the deployment and records the event', function () {
    $deployment = Deployment::factory()->create();

    syncEol($deployment, [eolFindingData($deployment)]);

    $finding = Finding::sole();
    expect($finding)
        ->state->toBe(FindingState::Open)
        ->team_id->toBe($deployment->service->project->team_id)
        ->group_key->toBe('runtime_eol:1:8.1')
        ->and($finding->events()->pluck('type')->all())->toBe([FindingEventType::Opened]);
});

it('changes nothing when run again with the same input', function () {
    $deployment = Deployment::factory()->create();

    syncEol($deployment, [eolFindingData($deployment)]);
    syncEol($deployment, [eolFindingData($deployment)]);

    expect(Finding::count())->toBe(1)
        ->and(Finding::sole()->events()->count())->toBe(1);
});

it('resolves an open finding that no longer matches and reopens it when it matches again', function () {
    $deployment = Deployment::factory()->create();

    syncEol($deployment, [eolFindingData($deployment)]);
    syncEol($deployment, []);

    expect(Finding::sole())->state->toBe(FindingState::Resolved)->resolved_at->not->toBeNull();

    Finding::sole()->update(['notified_severity' => Severity::High, 'notified_at' => now()]);
    syncEol($deployment, [eolFindingData($deployment)]);

    expect(Finding::sole())
        ->state->toBe(FindingState::Open)
        ->resolved_at->toBeNull()
        ->notified_severity->toBeNull()
        ->and(Finding::sole()->events()->pluck('type')->all())->toBe([FindingEventType::Opened, FindingEventType::Resolved, FindingEventType::Opened]);
});

it('records an escalation when the severity rises', function () {
    $deployment = Deployment::factory()->create();

    syncEol($deployment, [eolFindingData($deployment)]);
    syncEol($deployment, [eolFindingData($deployment, severity: Severity::Critical)]);

    expect(Finding::sole()->severity)->toBe(Severity::Critical)
        ->and(Finding::sole()->events()->latest('id')->first())
        ->type->toBe(FindingEventType::Escalated)
        ->data->toEqual(['from' => 'high', 'to' => 'critical']);
});

it('keeps open the findings the evaluation could not judge', function () {
    $deployment = Deployment::factory()->create();

    syncEol($deployment, [eolFindingData($deployment)]);
    syncEol($deployment, [], fn (Finding $finding): bool => true);

    expect(Finding::sole()->state)->toBe(FindingState::Open);
});

it('leaves findings of other rules and other deployments alone', function () {
    $deployment = Deployment::factory()->create();
    $packageFinding = Finding::factory()->for($deployment)->create(['rule' => FindingRule::DependencyVulnerable]);
    $otherDeploymentFinding = Finding::factory()->create();

    syncEol($deployment, []);

    expect($packageFinding->refresh()->state)->toBe(FindingState::Open)
        ->and($otherDeploymentFinding->refresh()->state)->toBe(FindingState::Open);
});

it('gives a new finding the deadline of its severity', function () {
    $this->travelTo('2026-10-23 10:00:00');
    $deployment = Deployment::factory()->create();

    syncEol($deployment, [eolFindingData($deployment, severity: Severity::Critical), eolFindingData($deployment, '8.0')]);

    expect(Finding::orderBy('id')->pluck('sla_due_at')->map->toDateTimeString()->all())->toBe(['2026-10-26 10:00:00', '2026-11-06 10:00:00']);
});

it('brings the deadline forward and asks for a new read when the severity rises, but never pushes it back', function () {
    $this->travelTo('2026-10-23 10:00:00');
    $deployment = Deployment::factory()->create();
    syncEol($deployment, [eolFindingData($deployment, severity: Severity::Medium)]);
    $finding = Finding::sole();
    $finding->update(['acknowledged_at' => now(), 'acknowledged_by' => User::factory()->admin()->create()->id]);

    $this->travelTo('2026-10-24 10:00:00');
    syncEol($deployment, [eolFindingData($deployment, severity: Severity::Critical)]);

    expect($finding->refresh())
        ->sla_due_at->toDateTimeString()->toBe('2026-10-27 10:00:00')
        ->acknowledged_at->toBeNull()
        ->acknowledged_by->toBeNull();

    $this->travelTo('2026-10-25 10:00:00');
    syncEol($deployment, [eolFindingData($deployment, severity: Severity::Low)]);
    syncEol($deployment, [eolFindingData($deployment, severity: Severity::High)]);

    expect($finding->refresh()->sla_due_at->toDateTimeString())->toBe('2026-10-27 10:00:00');
});

it('starts over when a resolved finding opens again', function () {
    $this->travelTo('2026-10-23 10:00:00');
    $deployment = Deployment::factory()->create();
    syncEol($deployment, [eolFindingData($deployment)]);
    Finding::sole()->update(['acknowledged_at' => now()]);
    syncEol($deployment, []);

    $this->travelTo('2026-11-01 10:00:00');
    syncEol($deployment, [eolFindingData($deployment)]);

    expect(Finding::sole())
        ->sla_due_at->toDateTimeString()->toBe('2026-11-15 10:00:00')
        ->acknowledged_at->toBeNull();
});

it('gives a finding its own deadline when it has one, e.g. the end-of-life date', function () {
    $this->travelTo('2026-10-23 10:00:00');
    $deployment = Deployment::factory()->create();
    $data = eolFindingData($deployment);

    syncEol($deployment, [new FindingData($data->fingerprint, $data->rule, $data->severity, $data->title, $data->groupKey, deadline: Carbon::parse('2026-01-01', 'Asia/Ho_Chi_Minh'))]);

    expect(Finding::sole()->sla_due_at->toDateTimeString())->toBe('2025-12-31 17:00:00');
});

it('takes the lock of the technology\'s incidents before locking any row when it stores a "thiếu bản vá" finding, and not otherwise', function () {
    $deployment = Deployment::factory()->create();
    $runtime = Runtime::factory()->for($deployment)->create(['technology' => Technology::Nodejs, 'raw_version' => '22.20.0', 'version' => '22.20.0', 'branch' => '22']);
    $outdated = new FindingData(
        fingerprint: FindingData::fingerprint(FindingRule::RuntimeOutdatedSecurity, $deployment->id, 'technology:nodejs', '22'),
        rule: FindingRule::RuntimeOutdatedSecurity,
        severity: Severity::High,
        title: 'Node.js 22 thiếu bản vá bảo mật, cần nâng lên 22.20.1',
        groupKey: 'runtime_outdated_security:nodejs:22.20.1',
        context: ['branch' => '22', 'latest' => '22.20.1'],
        runtimeId: $runtime->id,
    );
    $locks = fn (array $queries): array => collect($queries)->keys()->filter(fn (int $index): bool => str_contains($queries[$index]['query'], 'pg_advisory_xact_lock') && $queries[$index]['bindings'] === [crc32('incidents:nodejs')])->values()->all();
    DB::enableQueryLog();

    syncEol($deployment, [eolFindingData($deployment)]);

    expect($locks(DB::getQueryLog()))->toBe([]);

    DB::flushQueryLog();
    app(SyncFindings::class)->handle($deployment, RuntimeFindings::RULES, [$outdated]);

    $queries = DB::getQueryLog();
    $rowLock = collect($queries)->search(fn (array $query): bool => str_contains($query['query'], 'for update'));
    expect($locks($queries))->toHaveCount(1)
        ->and($locks($queries)[0])->toBeLessThan($rowLock);

    DB::flushQueryLog();
    app(SyncFindings::class)->handle($deployment, RuntimeFindings::RULES, []);

    expect($locks(DB::getQueryLog()))->toHaveCount(1)
        ->and(Finding::where('rule', FindingRule::RuntimeOutdatedSecurity)->sole()->state)->toBe(FindingState::Resolved);
});

/**
 * The CISA KEV evidence of the CVE ids, as KnownExploitation stores it.
 *
 * @param  array<string, string>  $dueDates  CVE id => the date CISA asks it fixed by.
 */
function knownExploitedEvidence(array $dueDates): array
{
    return collect($dueDates)->map(fn (string $dueDate, string $cve): array => ['cve' => $cve, 'name' => 'PHP RCE', 'date_added' => '2026-10-26', 'due_date' => $dueDate, 'listed' => true])->values()->all();
}

it('counts a finding CISA KEV newly lists a CVE of as not sent and unread, even at the same severity, and brings its deadline forward', function () {
    $this->travelTo('2026-10-27 10:00:00');
    $deployment = Deployment::factory()->create();
    $data = fn (array $dueDates): FindingData => app(KnownExploitation::class)->overlay(eolFindingData($deployment, severity: Severity::Critical), knownExploitedEvidence($dueDates));

    syncEol($deployment, [eolFindingData($deployment, severity: Severity::Critical)]);
    $finding = Finding::sole();
    $finding->update(['notified_severity' => Severity::Critical, 'notified_at' => now(), 'acknowledged_at' => now(), 'acknowledged_by' => User::factory()->create()->id, 'sla_due_at' => now()->addDays(20)]);

    syncEol($deployment, [$data(['CVE-2026-109618' => '2026-11-10'])]);

    expect($finding->refresh())
        ->severity->toBe(Severity::Critical)
        ->notified_severity->toBeNull()
        ->acknowledged_at->toBeNull()
        ->sla_due_at->toDateTimeString()->toBe('2026-10-30 10:00:00')
        ->and($finding->events()->where('type', FindingEventType::KnownExploited)->pluck('data')->all())->toBe([['cves' => ['CVE-2026-109618'], 'due_date' => '2026-11-10']]);

    $finding->update(['notified_severity' => Severity::Critical, 'notified_at' => now(), 'acknowledged_at' => now(), 'acknowledged_by' => User::factory()->create()->id]);
    $this->travel(1)->day();
    syncEol($deployment, [$data(['CVE-2026-109618' => '2026-10-29'])]);

    expect($finding->refresh())
        ->notified_severity->toBe(Severity::Critical)
        ->acknowledged_at->toBeNull()
        ->sla_due_at->toDateTimeString()->toBe('2026-10-29 16:59:59')
        ->and($finding->events()->where('type', FindingEventType::KnownExploited)->count())->toBe(1);

    syncEol($deployment, [$data(['CVE-2026-109618' => '2026-10-29', 'CVE-2026-109619' => '2026-10-29'])]);

    expect($finding->refresh())
        ->notified_severity->toBeNull()
        ->and($finding->events()->where('type', FindingEventType::KnownExploited)->pluck('data')->last())->toBe(['cves' => ['CVE-2026-109619'], 'due_date' => '2026-10-29']);
});
