<?php

use App\Actions\SyncServiceLockfiles;
use App\Events\DeploymentInventoryChanged;
use App\Jobs\SyncServiceLockfilesJob;
use App\Models\Lockfile;
use App\Models\Service;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

use function Pest\Laravel\artisan;

beforeEach(function () {
    Event::fake([DeploymentInventoryChanged::class]);
    fakeGoogleChat();
    $this->service = Service::factory()->syncingLockfiles('acme/shop')->create(['repository_path' => '/apps/api']);
});

it('reads the settings saved while it ran in a sync queued meanwhile', function () {
    config(['queue.default' => 'database']);
    Http::fake(['https://api.github.com/repos/*/contents/apps/api/composer.lock*' => function () {
        // An admin moves the service while the sync reads; saving queues a sync for the new directory.
        $this->service->update(['repository_path' => '/apps/web']);
        SyncServiceLockfilesJob::dispatch($this->service);

        return Http::response('{}');
    }]);
    fakeGitHub(['apps/api/composer.lock' => '{}', 'apps/web/package-lock.json' => '{}']);

    SyncServiceLockfilesJob::dispatch($this->service);
    artisan('queue:work', ['--once' => true, '--stop-when-empty' => true]);

    expect(Lockfile::count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(1);

    artisan('queue:work', ['--once' => true, '--stop-when-empty' => true]);

    expect(Lockfile::pluck('path')->all())->toBe(['package-lock.json'])
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('tries again later while another sync of the service runs', function () {
    Sleep::fake(syncWithCarbon: true);
    fakeGitHub(['apps/api/composer.lock' => '{}']);
    Cache::lock("lockfile-sync:service-{$this->service->id}", 600)->get();

    $job = (new SyncServiceLockfilesJob($this->service))->withFakeQueueInteractions();
    $job->handle(app(SyncServiceLockfiles::class));

    $job->assertReleased(30);
    expect(Lockfile::count())->toBe(0);
});
