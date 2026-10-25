<?php

use App\Actions\SyncTechnologyCatalog;
use App\Enums\Technology;
use App\Models\RuntimeBranch;
use App\Models\RuntimeRelease;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

it('stores the branches with their support dates and every release with its security flag', function () {
    $php = syncedTechnology(Technology::Php);

    $branch = RuntimeBranch::where('technology', $php)->where('branch', '8.3')->sole();
    expect($branch)
        ->latest_version->toBe('8.3.36')
        ->active_support_until->toDateString()->toBe('2025-12-31')
        ->security_support_until->toDateString()->toBe('2027-12-31')
        ->is_eol->toBeFalse()
        ->and(RuntimeBranch::where('technology', $php)->where('branch', '8.1')->sole()->is_eol)->toBeTrue()
        ->and(RuntimeRelease::where('technology', $php)->where('version', '8.3.36')->sole()->is_security_release)->toBeTrue()
        ->and(RuntimeRelease::where('technology', $php)->where('version', '8.3.30')->sole()->is_security_release)->toBeFalse();
});

it('records a successful sync on the technology heartbeat', function () {
    $php = syncedTechnology(Technology::Php);

    expect($php->catalogHeartbeat())
        ->last_success_at->not->toBeNull()
        ->last_error->toBeNull()
        ->isStale()->toBeFalse();
});

it('takes the newest version from either source as the latest of a branch', function () {
    $ruby = syncedTechnology(Technology::Ruby);

    expect(RuntimeBranch::where('technology', $ruby)->where('branch', '3.3')->value('latest_version'))->toBe('3.3.13');
});

it('skips ruby previews and release candidates', function () {
    $ruby = syncedTechnology(Technology::Ruby);

    expect(RuntimeRelease::where('technology', $ruby)->where('version', 'like', '%preview%')->orWhere('version', 'like', '%rc%')->count())->toBe(0)
        ->and(RuntimeRelease::where('technology', $ruby)->pluck('is_security_release')->unique()->all())->toBe([null]);
});

it('is idempotent', function () {
    $php = syncedTechnology(Technology::Php);
    $counts = [RuntimeBranch::count(), RuntimeRelease::count()];

    app(SyncTechnologyCatalog::class)->handle($php);

    expect([RuntimeBranch::count(), RuntimeRelease::count()])->toBe($counts);
});

it('records the failure and keeps the previous catalog when a source fails', function () {
    Sleep::fake();
    Http::preventStrayRequests();
    Http::fake([
        'https://endoflife.date/api/v1/products/php/' => Http::sequence()
            ->push(file_get_contents(base_path('tests/Fixtures/catalog/endoflife-php.json')))
            ->whenEmpty(Http::response('Service Unavailable', 503)),
        'https://www.php.net/releases/*' => Http::response(file_get_contents(base_path('tests/Fixtures/catalog/php-releases-8.json'))),
    ]);
    $php = Technology::Php;
    app(SyncTechnologyCatalog::class)->handle($php);

    expect(fn () => app(SyncTechnologyCatalog::class)->handle($php))->toThrow(RequestException::class);

    expect($php->catalogHeartbeat())
        ->last_failure_at->not->toBeNull()
        ->last_error->toContain('503')
        ->and(RuntimeBranch::where('technology', $php)->count())->toBe(4);
});
