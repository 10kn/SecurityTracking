<?php

use App\Actions\SyncTechnologyCatalog;
use App\Models\RuntimeBranch;
use App\Models\RuntimeRelease;
use App\Models\Technology;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

it('stores the branches with their support dates and every release with its security flag', function () {
    $php = syncedTechnology('php');

    $branch = $php->branches()->where('branch', '8.3')->sole();
    expect($branch)
        ->latest_version->toBe('8.3.36')
        ->active_support_until->toDateString()->toBe('2025-12-31')
        ->security_support_until->toDateString()->toBe('2027-12-31')
        ->is_eol->toBeFalse()
        ->and($php->branches()->where('branch', '8.1')->sole()->is_eol)->toBeTrue()
        ->and($php->releases()->where('version', '8.3.36')->sole()->is_security_release)->toBeTrue()
        ->and($php->releases()->where('version', '8.3.30')->sole()->is_security_release)->toBeFalse();
});

it('records a successful sync on the technology heartbeat', function () {
    $php = syncedTechnology('php');

    expect($php->catalogHeartbeat())
        ->last_success_at->not->toBeNull()
        ->last_error->toBeNull()
        ->isStale()->toBeFalse();
});

it('takes the newest version from either source as the latest of a branch', function () {
    $ruby = syncedTechnology('ruby');

    expect($ruby->branches()->where('branch', '3.3')->value('latest_version'))->toBe('3.3.13');
});

it('skips ruby previews and release candidates', function () {
    $ruby = syncedTechnology('ruby');

    expect($ruby->releases()->where('version', 'like', '%preview%')->orWhere('version', 'like', '%rc%')->count())->toBe(0)
        ->and($ruby->releases()->pluck('is_security_release')->unique()->all())->toBe([null]);
});

it('stores only the latest version of each branch for a technology without an official feed', function () {
    $python = syncedTechnology('python');

    expect($python->releases()->orderBy('version')->pluck('version')->all())->toBe(['3.12.15', '3.14.9', '3.9.25'])
        ->and($python->releases()->pluck('is_security_release')->unique()->all())->toBe([null]);
});

it('is idempotent', function () {
    $php = syncedTechnology('php');
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
    $php = Technology::factory()->php()->create();
    app(SyncTechnologyCatalog::class)->handle($php);

    expect(fn () => app(SyncTechnologyCatalog::class)->handle($php))->toThrow(RequestException::class);

    expect($php->catalogHeartbeat())
        ->last_failure_at->not->toBeNull()
        ->last_error->toContain('503')
        ->and($php->branches()->count())->toBe(4);
});

it('fails for a product endoflife.date does not know', function () {
    Http::preventStrayRequests();
    Http::fake(['https://endoflife.date/api/v1/products/*' => Http::response(['message' => 'Not Found'], 404)]);
    $technology = Technology::factory()->create(['endoflife_product' => 'not-a-product']);

    expect(fn () => app(SyncTechnologyCatalog::class)->handle($technology))->toThrow(RequestException::class);
});
