<?php

use App\Actions\UpdateProductionInventory;
use App\Enums\FindingRule;
use App\Enums\FindingState;
use App\Enums\RuntimeStatus;
use App\Enums\Technology;
use App\Models\Finding;
use App\Models\Service;
use Illuminate\Support\Sleep;

it('evaluates the runtimes right after an admin saves a new version', function () {
    $this->travelTo('2026-10-23 10:00:00');
    $php = syncedTechnology(Technology::Php);
    $service = Service::factory()->create();

    $deployment = app(UpdateProductionInventory::class)->handle($service, [$php->value => '8.3.35']);

    expect($deployment->runtimes()->sole())
        ->status->toBe(RuntimeStatus::OutdatedSecurity)
        ->version->toBe('8.3.35')
        ->branch->toBe('8.3')
        ->evaluated_at->not->toBeNull();
});

it('opens a finding for a runtime missing a security fix and alerts the team', function () {
    $this->travelTo('2026-10-23 10:00:00');
    fakeGoogleChat();
    $php = syncedTechnology(Technology::Php);
    $service = Service::factory()->create();
    $service->project->team->update(['google_chat_webhook_url' => chatWebhook('COMMERCE')]);

    app(UpdateProductionInventory::class)->handle($service, [$php->value => '8.3.35']);

    expect(Finding::sole())
        ->rule->toBe(FindingRule::RuntimeOutdatedSecurity)
        ->title->toBe('PHP 8.3 thiếu bản vá bảo mật, cần nâng lên 8.3.36')
        ->group_key->toBe("runtime_outdated_security:{$php->value}:8.3.36")
        ->notified_at->not->toBeNull()
        ->and(chatMessagesTo('COMMERCE'))->toHaveCount(1);
});

it('keeps the finding open while the runtime evaluates unknown', function () {
    $this->travelTo('2026-10-23 10:00:00');
    fakeGoogleChat();
    $php = syncedTechnology(Technology::Php);
    $service = Service::factory()->create();
    app(UpdateProductionInventory::class)->handle($service, [$php->value => '8.1.34']);

    app(UpdateProductionInventory::class)->handle($service, [$php->value => '8.1']);

    expect(Finding::sole()->state)->toBe(FindingState::Open);
});

it('resolves the findings of a runtime the admin removed', function () {
    $this->travelTo('2026-10-23 10:00:00');
    fakeGoogleChat();
    $php = syncedTechnology(Technology::Php);
    $service = Service::factory()->create();
    app(UpdateProductionInventory::class)->handle($service, [$php->value => '8.1.34']);

    app(UpdateProductionInventory::class)->handle($service, []);

    expect(Finding::sole()->state)->toBe(FindingState::Resolved);
});

it('scans the packages of an uploaded lockfile and resolves their findings when the lockfile is removed', function () {
    $this->travelTo('2026-10-23 10:00:00');
    fakeGoogleChat();
    fakeOsv();
    $service = Service::factory()->create();

    app(UpdateProductionInventory::class)->handle($service, [], [['name' => 'package-lock.json', 'content' => lockfileFixture('package-lock.json')]]);

    expect(Finding::open()->where('rule', FindingRule::DependencyVulnerable)->count())->toBe(7);

    app(UpdateProductionInventory::class)->handle($service, [], [], ['package-lock.json']);

    expect(Finding::open()->count())->toBe(0)
        ->and($service->productionDeployment->components()->count())->toBe(0);
});

it('still evaluates the runtimes and sends their alerts when the package source fails', function () {
    $this->travelTo('2026-10-23 10:00:00');
    Sleep::fake();
    $failing = true;
    fakePackagistOutage($failing);
    fakeGoogleChat();
    $php = syncedTechnology(Technology::Php);
    $service = Service::factory()->create();
    $service->project->team->update(['google_chat_webhook_url' => chatWebhook('COMMERCE')]);

    app(UpdateProductionInventory::class)->handle($service, [$php->value => '8.3.35'], [['name' => 'composer.lock', 'content' => lockfileFixture('composer.lock')]]);

    expect(Finding::sole()->rule)->toBe(FindingRule::RuntimeOutdatedSecurity)
        ->and(chatMessagesTo('COMMERCE'))->toHaveCount(1)
        ->and($service->productionDeployment->components()->count())->toBe(5);
});
