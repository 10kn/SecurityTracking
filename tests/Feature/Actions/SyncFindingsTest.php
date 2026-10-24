<?php

use App\Actions\SyncFindings;
use App\Enums\FindingEventType;
use App\Enums\FindingRule;
use App\Enums\FindingState;
use App\Enums\Severity;
use App\Models\Deployment;
use App\Models\Finding;
use App\Services\FindingData;

function eolFindingData(Deployment $deployment, string $branch = '8.1', Severity $severity = Severity::High): FindingData
{
    return new FindingData(
        fingerprint: FindingData::fingerprint(FindingRule::RuntimeEol, $deployment->id, 'technology:1', $branch),
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
