<?php

use App\Events\DeploymentInventoryChanged;
use App\Models\Lockfile;
use App\Models\Project;
use App\Models\Service;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

use function Pest\Laravel\artisan;

beforeEach(function () {
    Event::fake([DeploymentInventoryChanged::class]);
});

it('reads the lockfiles of every project that syncs them', function () {
    fakeGitHub(['composer.lock' => '{"packages":[]}']);
    $syncing = Service::factory()->for(Project::factory()->syncingLockfiles())->create(['repository_path' => '/']);
    $manual = Service::factory()->for(Project::factory())->create(['repository_path' => '/']);

    artisan('psm:sync-lockfiles')->assertSuccessful();

    expect(Lockfile::whereRelation('deployment', 'service_id', $syncing->id)->pluck('path')->all())->toBe(['composer.lock'])
        ->and(Lockfile::whereRelation('deployment', 'service_id', $manual->id)->exists())->toBeFalse();
});

it('fails when github cannot be reached', function () {
    Sleep::fake();
    Http::fake(['https://api.github.com/repos/*' => Http::response('Service Unavailable', 503)]);
    fakeGitHub();
    Project::factory()->syncingLockfiles()->create();

    artisan('psm:sync-lockfiles')->assertFailed();
});

it('runs once a day at the configured time', function () {
    $event = collect(app(Schedule::class)->events())->first(fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'psm:sync-lockfiles'));

    expect($event->expression)->toBe('30 6 * * *')
        ->and($event->timezone)->toBe('Asia/Ho_Chi_Minh');
});
