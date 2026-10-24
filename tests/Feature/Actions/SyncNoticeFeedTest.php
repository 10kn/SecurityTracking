<?php

use App\Actions\SyncNoticeFeed;
use App\Enums\AdvisoryKind;
use App\Enums\Severity;
use App\Models\Advisory;
use App\Models\SourceHeartbeat;
use App\Models\Technology;
use App\Services\Notices\PhpNetNoticeFeed;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    $this->travelTo('2026-10-23 10:00:00');
});

it('stores the security news of php.net as pre-notices and leaves release announcements to the catalog sync', function () {
    fakeNoticeFeeds();
    $php = Technology::factory()->php()->create();

    app(SyncNoticeFeed::class)->handle($php);

    expect(Advisory::orderByDesc('published_at')->pluck('title')->all())->toBe([
        'Security advisory: Critical remote code execution vulnerabilities, update now',
        'Upcoming Critical PHP Security Release',
        'PHPKonf 2025 Baku',
    ]);

    $preNotice = Advisory::where('external_id', 'https://www.php.net/archive/2026.php#2026-10-20-1')->sole();
    expect($preNotice)
        ->kind->toBe(AdvisoryKind::PreNotice)
        ->source->toBe('notice:php_net')
        ->technology_id->toBe($php->id)
        ->severity->toBe(Severity::Critical)
        ->cvss_score->toBe('9.2')
        ->url->toBe('https://www.php.net/archive/2026.php#2026-10-20-1')
        ->published_at->toIso8601String()->toBe('2026-10-20T20:32:00+00:00')
        ->summary->toContain('advance notice of an upcoming security release', '21 October 2026 at 14:00 UTC')
        ->and(Advisory::where('title', 'like', 'Security advisory%')->sole())
        ->aliases->toBe(['CVE-2026-109618', 'CVE-2026-109619'])
        ->summary->toHaveLength(503);
});

it('keeps every entry of the nodejs.org vulnerability feed and only the security news of ruby-lang.org', function () {
    fakeNoticeFeeds();

    app(SyncNoticeFeed::class)->handle(Technology::factory()->nodejs()->create());
    app(SyncNoticeFeed::class)->handle(Technology::factory()->ruby()->create());

    expect(Advisory::where('source', 'notice:nodejs')->count())->toBe(3)
        ->and(Advisory::where('source', 'notice:ruby_lang')->orderBy('published_at')->pluck('title')->all())->toBe([
            'Security advisories: CVE-2026-80212 and CVE-2026-80213',
            "Regexp compilation fixes related to GitLab's CVE-2026-89078, CVE-2026-93577, CVE-2026-97229 and CVE-2026-110264",
        ]);
});

it('follows the configured keywords', function () {
    config(['psm.notices.keywords' => ['conference']]);
    fakeNoticeFeeds();

    app(SyncNoticeFeed::class)->handle(Technology::factory()->php()->create());

    expect(Advisory::pluck('title')->all())->toContain('Dutch PHP Conference 2027')->not->toContain('Upcoming Critical PHP Security Release');
});

it('is idempotent and follows edits the vendor makes to an entry', function () {
    $feed = noticeFixture('php-feed.atom');
    Http::fake([PhpNetNoticeFeed::URL => function () use (&$feed) {
        return Http::response($feed);
    }]);
    $php = Technology::factory()->php()->create();
    app(SyncNoticeFeed::class)->handle($php);
    $before = Advisory::orderBy('id')->get(['id', 'updated_at'])->toArray();

    $this->travel(15)->minutes();
    $advisories = app(SyncNoticeFeed::class)->handle($php);

    expect(Advisory::orderBy('id')->get(['id', 'updated_at'])->toArray())->toBe($before)
        ->and(collect($advisories)->contains(fn (Advisory $advisory): bool => $advisory->wasRecentlyCreated || $advisory->wasChanged()))->toBeFalse();

    $feed = str_replace('Upcoming Critical PHP Security Release', 'Upcoming Critical PHP Security Release (updated)', $feed);
    app(SyncNoticeFeed::class)->handle($php);

    expect(Advisory::count())->toBe(3)
        ->and(Advisory::where('external_id', 'https://www.php.net/archive/2026.php#2026-10-20-1')->value('title'))->toBe('Upcoming Critical PHP Security Release (updated)');
});

it('records a successful poll on the feed heartbeat', function () {
    fakeNoticeFeeds();

    app(SyncNoticeFeed::class)->handle(Technology::factory()->php()->create());

    expect(SourceHeartbeat::where('source', 'notice:php_net')->sole())
        ->name->toBe('Tin bảo mật php.net')
        ->threshold_minutes->toBe(120)
        ->last_success_at->not->toBeNull()
        ->last_error->toBeNull();
});

it('records a failed poll on the feed heartbeat and rethrows', function () {
    Sleep::fake();
    Http::fake([PhpNetNoticeFeed::URL => Http::response('Service Unavailable', 503)]);
    $php = Technology::factory()->php()->create();

    expect(fn () => app(SyncNoticeFeed::class)->handle($php))->toThrow(RequestException::class);

    expect(SourceHeartbeat::where('source', 'notice:php_net')->sole())
        ->last_success_at->toBeNull()
        ->last_failure_at->not->toBeNull()
        ->last_error->toStartWith('RequestException')
        ->and(Advisory::count())->toBe(0);
});

it('polls nothing for a technology whose release source has no news feed', function () {
    Http::preventStrayRequests();

    expect(app(SyncNoticeFeed::class)->handle(Technology::factory()->create()))->toBe([])
        ->and(SourceHeartbeat::count())->toBe(0);
});
