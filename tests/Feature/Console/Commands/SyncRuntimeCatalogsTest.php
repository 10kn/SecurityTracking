<?php

use App\Enums\RuntimeStatus;
use App\Enums\Technology;
use App\Models\Runtime;
use App\Models\RuntimeBranch;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

use function Pest\Laravel\artisan;

it('syncs every technology and evaluates the runtimes that use it', function () {
    $this->travelTo('2026-10-23 10:00:00');
    fakeCatalogSources();
    $runtime = Runtime::factory()->create(['technology' => Technology::Nodejs, 'raw_version' => '20.18.0']);

    artisan('psm:sync-runtimes')->assertSuccessful();

    expect(RuntimeBranch::toBase()->selectRaw('technology, count(*) as aggregate')->groupBy('technology')->pluck('aggregate', 'technology')->all())
        ->toEqual(['nodejs' => 3, 'php' => 4, 'ruby' => 3])
        ->and($runtime->refresh()->status)->toBe(RuntimeStatus::Eol);
});

it('keeps syncing the other technologies when one source fails and exits with a failure', function () {
    Sleep::fake();
    Http::fake(['https://endoflife.date/api/v1/products/ruby/' => Http::response('Service Unavailable', 503)]);
    fakeCatalogSources();

    artisan('psm:sync-runtimes')->assertFailed();

    expect(RuntimeBranch::where('technology', Technology::Php)->count())->toBe(4)
        ->and(RuntimeBranch::where('technology', Technology::Ruby)->count())->toBe(0);
});

it('syncs only the technologies given', function () {
    fakeCatalogSources();

    artisan('psm:sync-runtimes', ['--technology' => ['ruby']])->assertSuccessful();

    expect(RuntimeBranch::where('technology', Technology::Php)->count())->toBe(0)
        ->and(RuntimeBranch::where('technology', Technology::Ruby)->count())->toBe(3);
});

it('is scheduled hourly, the end-of-life evaluation daily at 00:05, the source check every 15 minutes and the digest at 08:00 Vietnam time', function () {
    $events = collect(app(Schedule::class)->events())
        ->keyBy(fn (Event $event): string => trim(str($event->command)->after('artisan')->replace(["'", '"'], '')));

    expect($events->get('psm:sync-runtimes')->expression)->toBe('0 * * * *')
        ->and($events->get('psm:evaluate-runtimes')->expression)->toBe('5 0 * * *')
        ->and($events->get('psm:check-sources')->expression)->toBe('*/15 * * * *')
        ->and($events->get('psm:send-digest'))
        ->expression->toBe('0 8 * * 0,1,2,3,4,5,6')
        ->timezone->toBe('Asia/Ho_Chi_Minh');
});
