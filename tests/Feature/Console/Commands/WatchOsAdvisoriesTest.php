<?php

use App\Enums\Severity;
use App\Models\Advisory;
use App\Models\SourceHeartbeat;
use App\Services\OsAdvisories\AmazonLinuxAlasFeed;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\artisan;

beforeEach(function () {
    $this->travelTo('2026-11-09 10:00:00');
    fakeGoogleChat();
});

it('polls the advisories of every operating system, and sends nothing of a first poll', function () {
    fakeOsAdvisoryFeeds();

    artisan('psm:watch-os-advisories')->assertSuccessful();

    expect(Advisory::groupBy('source')->selectRaw('source, count(*) as total')->pluck('total', 'source')->all())
        ->toEqual(['os:amazon-linux-2' => 2, 'os:amazon-linux-2023' => 3, 'os:debian' => 3, 'os:ubuntu' => 3])
        ->and(SourceHeartbeat::orderBy('source')->pluck('source')->all())->toBe(['os:amazon-linux-2', 'os:amazon-linux-2023', 'os:debian', 'os:ubuntu'])
        ->and(chatMessagesTo('NEWS'))->toBeEmpty();
});

it('sends the new advisories at an immediate severity on the next polls', function () {
    fakeOsAdvisoryFeeds();
    artisan('psm:watch-os-advisories')->assertSuccessful();
    Advisory::where('external_id', 'ALAS2023-2026-3338')->delete();

    artisan('psm:watch-os-advisories')->assertSuccessful();

    expect(chatMessagesTo('NEWS')->pluck('text')->all())->toBe(['🟠 [High] ALAS2023-2026-3338: emacs'])
        ->and(Advisory::firstWhere('external_id', 'ALAS2023-2026-3338')->severity)->toBe(Severity::High);
});

it('polls only the operating systems asked for', function () {
    fakeOsAdvisoryFeeds();

    artisan('psm:watch-os-advisories', ['--os' => ['debian']])->assertSuccessful();

    expect(Advisory::pluck('source')->unique()->values()->all())->toBe(['os:debian']);
});

it('keeps polling the other feeds when one fails, and fails', function () {
    Http::fake([AmazonLinuxAlasFeed::AL2_URL => Http::response('Service Unavailable', 503)]);
    fakeOsAdvisoryFeeds();

    artisan('psm:watch-os-advisories')->assertFailed();

    expect(Advisory::where('source', 'os:amazon-linux-2')->count())->toBe(0)
        ->and(Advisory::where('source', 'os:ubuntu')->count())->toBe(3)
        ->and(SourceHeartbeat::firstWhere('source', 'os:amazon-linux-2')->last_error)->toContain('503');
});

it('is scheduled every 30 minutes', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains((string) $event->command, 'psm:watch-os-advisories'));

    expect($event)
        ->expression->toBe('*/30 * * * *')
        ->withoutOverlapping->toBeTrue();
});
