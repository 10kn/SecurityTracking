<?php

use App\Enums\FindingState;
use App\Models\Deployment;
use App\Models\Finding;
use App\Models\Lockfile;
use App\Models\Project;
use App\Models\Service;
use App\Models\Team;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Sleep;

use function Pest\Laravel\artisan;

beforeEach(function () {
    $this->travelTo('2026-10-23 10:00:00');
    fakeGoogleChat();
    $team = Team::factory()->create(['google_chat_webhook_url' => chatWebhook('COMMERCE')]);
    $this->deployment = Deployment::factory()->for(Service::factory()->for(Project::factory()->for($team)))->create();
});

it('reads lockfiles not read yet, scans every current deployment and alerts the teams', function () {
    fakePackagist();
    fakeOsv();
    Lockfile::factory()->for($this->deployment)->withContent('composer.lock', lockfileFixture('composer.lock'))->create();
    $old = Deployment::factory()->for($this->deployment->service)->create(['is_current' => false]);
    Lockfile::factory()->for($old)->withContent('Gemfile.lock', lockfileFixture('Gemfile.lock'))->create();

    artisan('psm:scan-packages')->assertSuccessful();

    expect($this->deployment->components()->count())->toBe(5)
        ->and($old->components()->count())->toBe(0)
        ->and(Finding::open()->count())->toBe(4)
        ->and(chatMessagesTo('COMMERCE'))->toHaveCount(1)
        ->and(chatJson(chatMessagesTo('COMMERCE')->sole()))->toContain('[Critical] GHSA-8xf4-w7qw-pjjw trong firebase/php-jwt', 'Key/algorithm type confusion', 'nâng lên 6.0.0', 'https://github.com/advisories/GHSA-8xf4-w7qw-pjjw');
});

it('resolves the findings of a deployment whose lockfiles were all removed', function () {
    fakePackagist();
    $lockfile = Lockfile::factory()->for($this->deployment)->withContent('composer.lock', lockfileFixture('composer.lock'))->create();
    artisan('psm:scan-packages')->assertSuccessful();

    $lockfile->delete();
    artisan('psm:scan-packages')->assertSuccessful();

    expect(Finding::pluck('state')->unique()->all())->toBe([FindingState::Resolved])
        ->and($this->deployment->components()->count())->toBe(0);
});

it('fails, keeping the findings open, when a source fails', function () {
    Sleep::fake();
    $failing = false;
    fakePackagistOutage($failing);
    fakePackagist();
    Lockfile::factory()->for($this->deployment)->withContent('composer.lock', lockfileFixture('composer.lock'))->create();
    artisan('psm:scan-packages')->assertSuccessful();

    $failing = true;
    artisan('psm:scan-packages')->assertFailed();

    expect(Finding::open()->count())->toBe(4);
});

it('makes no request when there is nothing to scan', function () {
    fakePackagist();
    fakeRubySec();
    fakeOsv();

    artisan('psm:scan-packages')->assertSuccessful();

    expect(osvRequests())->toHaveCount(0)
        ->and(packagistRequests())->toHaveCount(0)
        ->and(rubySecRequests())->toHaveCount(0);
});

it('is scheduled every 6 hours', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains((string) $event->command, 'psm:scan-packages'));

    expect($event)
        ->expression->toBe('0 */6 * * *')
        ->withoutOverlapping->toBeTrue();
});
