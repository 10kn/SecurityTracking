<?php

use App\Actions\UpdateProductionInventory;
use App\Enums\FindingRule;
use App\Enums\FindingState;
use App\Enums\RuntimeStatus;
use App\Enums\Severity;
use App\Enums\Technology;
use App\Models\Finding;
use App\Models\Project;
use App\Models\Service;
use App\Models\Team;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

use function Pest\Laravel\artisan;

/*
 * The end-to-end scenarios of handover 5.6: from a change in a source or in the inventory to the Google Chat message.
 */

/**
 * Fakes the PHP catalog as it was before 8.3.36 came out on 2026-10-21, until $released turns true.
 */
function fakePhpCatalogBefore8336(bool &$released): void
{
    $fixture = fn (string $name): array => json_decode((string) file_get_contents(base_path("tests/Fixtures/catalog/{$name}")), true);

    $releases = $fixture('php-releases-8.json');
    $branches = $fixture('endoflife-php.json');
    $releasesBefore = array_diff_key($releases, ['8.3.36' => true]);
    $branchesBefore = $branches;

    foreach ($branchesBefore['result']['releases'] as &$branch) {
        if ($branch['name'] === '8.3') {
            $branch['latest']['name'] = '8.3.35';
        }
    }

    Http::fake([
        'https://www.php.net/releases/*' => function () use (&$released, $releases, $releasesBefore) {
            return Http::response($released ? $releases : $releasesBefore);
        },
        'https://endoflife.date/api/v1/products/php/' => function () use (&$released, $branches, $branchesBefore) {
            return Http::response($released ? $branches : $branchesBefore);
        },
    ]);
}

function teamWithChatSpace(string $name, string $space): Team
{
    return Team::factory()->create(['name' => $name, 'google_chat_webhook_url' => chatWebhook($space)]);
}

function serviceOfTeam(Team $team, string $name): Service
{
    return Service::factory()->for(Project::factory()->for($team))->create(['name' => $name, 'slug' => $name]);
}

beforeEach(function () {
    fakeGoogleChat();
});

it('sends one alert to the owning team when a security release comes out, and none when the sync runs again', function () {
    $this->travelTo('2026-10-20 10:00:00');
    $released = false;
    fakePhpCatalogBefore8336($released);
    fakeCatalogSources();
    artisan('psm:sync-runtimes')->assertSuccessful();

    $commerce = teamWithChatSpace('Commerce', 'COMMERCE');
    $booking = teamWithChatSpace('Booking', 'BOOKING');
    $shopApi = serviceOfTeam($commerce, 'shop-api');
    app(UpdateProductionInventory::class)->handle($shopApi, ['php' => '8.3.35']);
    app(UpdateProductionInventory::class)->handle(serviceOfTeam($booking, 'booking-api'), ['php' => '8.4.27']);

    expect($shopApi->productionDeployment->runtimes()->sole()->status)->toBe(RuntimeStatus::Ok)
        ->and(chatMessagesTo('COMMERCE'))->toHaveCount(0);

    $this->travelTo('2026-10-21 15:00:00');
    $released = true;
    artisan('psm:sync-runtimes')->assertSuccessful();
    artisan('psm:sync-runtimes')->assertSuccessful();

    $messages = chatMessagesTo('COMMERCE');
    expect($messages)->toHaveCount(1)
        ->and(chatJson($messages->sole()))->toContain('shop-api', '8.3.36', 'High')
        ->and(chatMessagesTo('BOOKING'))->toHaveCount(0);
});

it('closes the old finding and opens an end-of-life one when the version moves to an end-of-life branch, then reports the fix in the weekly summary', function () {
    $this->travelTo('2026-10-23 10:00:00');
    syncedTechnology(Technology::Php);
    $commerce = teamWithChatSpace('Commerce', 'COMMERCE');
    $shopApi = serviceOfTeam($commerce, 'shop-api');
    $inventory = app(UpdateProductionInventory::class);

    $inventory->handle($shopApi, ['php' => '8.3.35']);
    $inventory->handle($shopApi, ['php' => '8.1.34']);

    $findings = Finding::orderBy('id')->get();
    expect($findings->pluck('rule')->all())->toBe([FindingRule::RuntimeOutdatedSecurity, FindingRule::RuntimeEol])
        ->and($findings->pluck('state')->all())->toBe([FindingState::Resolved, FindingState::Open])
        ->and(chatMessagesTo('COMMERCE'))->toHaveCount(2)
        ->and(chatJson(chatMessagesTo('COMMERCE')->last()))->toContain('8.1');

    $inventory->handle($shopApi, ['php' => '8.3.36']);

    expect(Finding::open()->count())->toBe(0)
        ->and(chatMessagesTo('COMMERCE'))->toHaveCount(2);

    $this->travelTo('2026-10-24 01:00:00');
    artisan('psm:send-digest')->assertSuccessful();

    expect(chatMessagesTo('COMMERCE'))->toHaveCount(2);

    $this->travelTo('2026-10-26 01:00:00');
    artisan('psm:send-digest')->assertSuccessful();

    $digest = chatMessagesTo('COMMERCE')->last();
    expect(chatJson($digest))->toContain('Tổng kết tuần', 'Đã tự đóng: 2', 'PHP 8.1 hết hỗ trợ');
});

it('lists every affected service of a team in one alert', function () {
    $this->travelTo('2026-10-23 10:00:00');
    syncedTechnology(Technology::Php);
    $commerce = teamWithChatSpace('Commerce', 'COMMERCE');
    $booking = teamWithChatSpace('Booking', 'BOOKING');

    foreach (['shop-api', 'shop-admin', 'partner-portal'] as $name) {
        serviceOfTeam($commerce, $name)->deployments()->create(['environment' => 'production'])
            ->runtimes()->create(['technology' => Technology::Php, 'raw_version' => '8.3.35', 'source' => 'manual', 'observed_at' => now()]);
    }

    serviceOfTeam($booking, 'booking-api')->deployments()->create(['environment' => 'production'])
        ->runtimes()->create(['technology' => Technology::Php, 'raw_version' => '8.3.33', 'source' => 'manual', 'observed_at' => now()]);

    artisan('psm:sync-runtimes')->assertSuccessful();

    $commerceMessages = chatMessagesTo('COMMERCE');
    expect($commerceMessages)->toHaveCount(1)
        ->and(chatJson($commerceMessages->sole()))->toContain('shop-api', 'shop-admin', 'partner-portal')->not->toContain('booking-api')
        ->and(chatMessagesTo('BOOKING'))->toHaveCount(1);
});

it('turns php runtimes unknown and alerts the admin space once when php.net fails past its threshold', function () {
    Sleep::fake();
    $this->travelTo('2026-10-23 10:00:00');
    $failing = false;
    Http::fake(['https://www.php.net/releases/*' => function () use (&$failing) {
        return $failing ? Http::response('Service Unavailable', 503) : Http::response((string) file_get_contents(base_path('tests/Fixtures/catalog/php-releases-8.json')));
    }]);
    fakeCatalogSources();
    artisan('psm:sync-runtimes')->assertSuccessful();
    $runtime = serviceOfTeam(teamWithChatSpace('Commerce', 'COMMERCE'), 'shop-api')->deployments()->create(['environment' => 'production'])
        ->runtimes()->create(['technology' => Technology::Php, 'raw_version' => '8.3.35', 'source' => 'manual', 'observed_at' => now()]);
    artisan('psm:evaluate-runtimes')->assertSuccessful();
    expect($runtime->refresh()->status)->toBe(RuntimeStatus::OutdatedSecurity);

    $failing = true;
    foreach (range(1, 4) as $hour) {
        $this->travel(1)->hour();
        artisan('psm:sync-runtimes')->assertFailed();
        artisan('psm:check-sources')->assertSuccessful();
    }

    $adminMessages = chatMessagesTo('ADMIN');
    expect($runtime->refresh()->status)->toBe(RuntimeStatus::Unknown)
        ->and($adminMessages)->toHaveCount(1)
        ->and(chatJson($adminMessages->sole()))->toContain('PHP', 'lỗi quá ngưỡng')
        ->and(Finding::open()->count())->toBe(1);
});

it('warns each team running php once when php.net pre-announces a critical security release, and not again on the next polls', function () {
    $this->travelTo('2026-10-20 20:45:00');
    fakeNoticeFeeds();
    $inventory = app(UpdateProductionInventory::class);
    $commerce = teamWithChatSpace('Commerce', 'COMMERCE');
    $booking = teamWithChatSpace('Booking', 'BOOKING');
    $analytics = teamWithChatSpace('Analytics', 'ANALYTICS');
    $inventory->handle(serviceOfTeam($commerce, 'shop-api'), ['php' => '8.3.35']);
    $inventory->handle(serviceOfTeam($commerce, 'shop-web'), ['php' => '8.4.26', 'nodejs' => '22.20.0']);
    $inventory->handle(serviceOfTeam($booking, 'booking-api'), ['php' => '8.2.34']);
    $inventory->handle(serviceOfTeam($analytics, 'events'), ['nodejs' => '22.20.0']);

    artisan('psm:watch-notices')->assertSuccessful();

    $commerceMessage = chatMessagesTo('COMMERCE')->sole();
    expect($commerceMessage['text'])->toBe('[Critical] Upcoming Critical PHP Security Release')
        ->and(chatJson($commerceMessage))->toContain('shop-api', 'PHP 8.3.35', 'shop-web', 'PHP 8.4.26', 'https://www.php.net/archive/2026.php#2026-10-20-1')
        ->and(chatJson(chatMessagesTo('BOOKING')->sole()))->toContain('Upcoming Critical PHP Security Release', 'booking-api', 'PHP 8.2.34')
        ->and(chatMessagesTo('ANALYTICS'))->toHaveCount(0);

    $this->travel(15)->minutes();
    artisan('psm:watch-notices')->assertSuccessful();
    $this->travel(15)->minutes();
    artisan('psm:watch-notices')->assertSuccessful();

    expect(chatMessagesTo('COMMERCE'))->toHaveCount(1)
        ->and(chatMessagesTo('BOOKING'))->toHaveCount(1)
        ->and(chatMessagesTo('ANALYTICS'))->toHaveCount(0);

    $this->travelTo('2026-10-21 01:00:00');
    artisan('psm:watch-notices')->assertSuccessful();

    expect(chatMessagesTo('COMMERCE')->pluck('text')->all())->toBe([
        '[Critical] Upcoming Critical PHP Security Release',
        '[Critical] Security advisory: Critical remote code execution vulnerabilities, update now',
    ])
        ->and(chatMessagesTo('BOOKING'))->toHaveCount(2)
        ->and(chatMessagesTo('ANALYTICS'))->toHaveCount(0);
});

it('tells each team once that the fix is out when the vendor names the version and the catalog lists it, standing for the "thiếu bản vá" finding', function () {
    $this->travelTo('2026-10-20 20:45:00');
    $released = false;
    fakePhpCatalogBefore8336($released);
    fakeCatalogSources();
    fakeNoticeFeeds();
    artisan('psm:sync-runtimes')->assertSuccessful();
    $inventory = app(UpdateProductionInventory::class);
    $commerce = teamWithChatSpace('Commerce', 'COMMERCE');
    $booking = teamWithChatSpace('Booking', 'BOOKING');
    $inventory->handle(serviceOfTeam($commerce, 'shop-api'), ['php' => '8.3.35']);
    $inventory->handle(serviceOfTeam($booking, 'booking-api'), ['php' => '8.4.27']);

    artisan('psm:watch-notices')->assertSuccessful();

    $this->travelTo('2026-10-21 01:00:00');
    artisan('psm:watch-notices')->assertSuccessful();

    expect(chatMessagesTo('COMMERCE')->pluck('text')->all())->toBe([
        '[Critical] Upcoming Critical PHP Security Release',
        '[Critical] Security advisory: Critical remote code execution vulnerabilities, update now',
    ]);

    $this->travelTo('2026-10-21 15:00:00');
    $released = true;
    artisan('psm:sync-runtimes')->assertSuccessful();

    expect(chatMessagesTo('COMMERCE'))->toHaveCount(2);

    $this->travel(15)->minutes();
    artisan('psm:watch-notices')->assertSuccessful();
    $this->travel(1)->hour();
    artisan('psm:sync-runtimes')->assertSuccessful();
    artisan('psm:watch-notices')->assertSuccessful();

    $finding = Finding::where('rule', FindingRule::RuntimeOutdatedSecurity)->sole();
    expect(chatMessagesTo('COMMERCE')->pluck('text')->all())->toBe([
        '[Critical] Upcoming Critical PHP Security Release',
        '[Critical] Security advisory: Critical remote code execution vulnerabilities, update now',
        '[Critical] Đã có bản vá, nâng lên 8.3.36',
    ])
        ->and(chatJson(chatMessagesTo('COMMERCE')->last()))->toContain('shop-api', 'PHP 8.3.35 → 8.3.36', 'Hạn xử lý: 24/10/2026.')
        ->and(chatMessagesTo('BOOKING')->pluck('text')->all())->toBe([
            '[Critical] Upcoming Critical PHP Security Release',
            '[Critical] Security advisory: Critical remote code execution vulnerabilities, update now',
        ])
        ->and($finding)->severity->toBe(Severity::Critical)->notified_severity->toBe(Severity::Critical);
});

it('alerts the owning team right after a lockfile with a vulnerable package is uploaded', function () {
    $this->travelTo('2026-10-23 10:00:00');
    fakePackagist();
    $commerce = teamWithChatSpace('Commerce', 'COMMERCE');
    teamWithChatSpace('Booking', 'BOOKING');

    app(UpdateProductionInventory::class)->handle(serviceOfTeam($commerce, 'shop-api'), [], [['path' => 'composer.lock', 'content' => lockfileFixture('composer.lock')]]);

    $message = chatMessagesTo('COMMERCE')->sole();
    expect($message['text'])->toBe('[Critical] GHSA-8xf4-w7qw-pjjw trong firebase/php-jwt')
        ->and(chatJson($message))->toContain('shop-api', 'firebase/php-jwt v5.5.1 · nâng lên 6.0.0 · composer.lock')
        ->and(chatMessagesTo('BOOKING'))->toHaveCount(0)
        ->and(Finding::where('rule', FindingRule::DependencyVulnerable)->open()->count())->toBe(4);
});

it('sends each team one card listing its affected services when Packagist publishes a new advisory, and nothing when the scan runs again', function () {
    $this->travelTo('2026-10-23 10:00:00');
    $published = false;
    fakePackagist(function (string $id) use (&$published): bool {
        return $published || $id !== 'GHSA-8xf4-w7qw-pjjw';
    });
    $commerce = teamWithChatSpace('Commerce', 'COMMERCE');
    $booking = teamWithChatSpace('Booking', 'BOOKING');
    $lockfile = [['path' => 'composer.lock', 'content' => lockfileFixture('composer.lock')]];
    $inventory = app(UpdateProductionInventory::class);
    $inventory->handle(serviceOfTeam($commerce, 'shop-api'), [], $lockfile);
    $inventory->handle(serviceOfTeam($commerce, 'shop-admin'), [], $lockfile);
    $inventory->handle(serviceOfTeam($booking, 'booking-api'), [], $lockfile);

    expect(chatMessagesTo('COMMERCE'))->toHaveCount(0)
        ->and(chatMessagesTo('BOOKING'))->toHaveCount(0);

    $published = true;
    $this->travel(6)->hours();
    artisan('psm:scan-packages')->assertSuccessful();
    artisan('psm:scan-packages')->assertSuccessful();

    $commerceMessage = chatMessagesTo('COMMERCE')->sole();
    expect($commerceMessage['text'])->toBe('[Critical] GHSA-8xf4-w7qw-pjjw trong firebase/php-jwt')
        ->and(chatJson($commerceMessage))->toContain('2 service bị ảnh hưởng', 'shop-api', 'shop-admin')->not->toContain('booking-api')
        ->and(chatJson(chatMessagesTo('BOOKING')->sole()))->toContain('GHSA-8xf4-w7qw-pjjw', 'booking-api')->not->toContain('shop-api');
});

/**
 * Fakes the root tree of the repository with the given lockfiles, by path, while $isActive is true; fakeGitHub()
 * answers otherwise.
 *
 * @param  array<string, string>  $files
 */
function fakeRepositoryTree(bool &$isActive, array $files): void
{
    Http::fake(['https://api.github.com/repos/*/git/trees/*' => function () use (&$isActive, $files) {
        return $isActive ? Http::response(['sha' => GITHUB_COMMIT, 'truncated' => false, 'tree' => collect($files)
            ->map(fn (string $content, string $path): array => ['path' => $path, 'mode' => '100644', 'type' => 'blob', 'sha' => sha1($content), 'size' => strlen($content)])
            ->values()
            ->all()]) : null;
    }]);
}

it('alerts once for a vulnerable package in a nested lockfile from github, and closes its findings when the lockfile is gone', function () {
    $this->travelTo('2026-10-23 10:00:00');
    $isRemoved = false;
    fakeRepositoryTree($isRemoved, ['composer.lock' => '{"packages":[]}']);
    fakePackagist();
    fakeGitHub(['composer.lock' => '{"packages":[]}', 'admin/composer.lock' => lockfileFixture('composer.lock')]);
    $commerce = teamWithChatSpace('Commerce', 'COMMERCE');
    $service = serviceOfTeam($commerce, 'shop-api');
    $service->update(['repository' => 'acme/shop', 'repository_path' => '/']);

    artisan('psm:sync-lockfiles')->assertSuccessful();
    artisan('psm:sync-lockfiles')->assertSuccessful();

    $message = chatMessagesTo('COMMERCE')->sole();
    expect($message['text'])->toBe('[Critical] GHSA-8xf4-w7qw-pjjw trong firebase/php-jwt')
        ->and(chatJson($message))->toContain('firebase/php-jwt v5.5.1 · nâng lên 6.0.0 · admin/composer.lock')
        ->and(Finding::where('rule', FindingRule::DependencyVulnerable)->open()->count())->toBe(4);

    $isRemoved = true;
    $this->travel(1)->day();
    artisan('psm:sync-lockfiles')->assertSuccessful();

    expect($service->productionDeployment->lockfiles()->pluck('path')->all())->toBe(['composer.lock'])
        ->and(Finding::where('rule', FindingRule::DependencyVulnerable)->open()->count())->toBe(0)
        ->and(Finding::where('rule', FindingRule::DependencyVulnerable)->where('state', FindingState::Resolved)->count())->toBe(4)
        ->and(chatMessagesTo('COMMERCE'))->toHaveCount(1);
});

it('keeps one finding for a package in a root and a nested lockfile, open until both are gone', function () {
    $this->travelTo('2026-10-23 10:00:00');
    $isRemoved = false;
    fakeRepositoryTree($isRemoved, ['composer.lock' => lockfileFixture('composer.lock')]);
    fakePackagist();
    fakeGitHub(['composer.lock' => lockfileFixture('composer.lock'), 'admin/composer.lock' => lockfileFixture('composer.lock')]);
    $commerce = teamWithChatSpace('Commerce', 'COMMERCE');
    $service = serviceOfTeam($commerce, 'shop-api');
    $service->update(['repository' => 'acme/shop', 'repository_path' => '/']);

    artisan('psm:sync-lockfiles')->assertSuccessful();

    $finding = Finding::where('rule', FindingRule::DependencyVulnerable)->where('context->advisory', 'GHSA-8xf4-w7qw-pjjw')->sole();
    expect(Finding::where('rule', FindingRule::DependencyVulnerable)->open()->count())->toBe(4)
        ->and($finding->lockfilePaths())->toBe(['admin/composer.lock', 'composer.lock'])
        ->and(chatJson(chatMessagesTo('COMMERCE')->sole()))->toContain('firebase/php-jwt v5.5.1 · nâng lên 6.0.0 · admin/composer.lock, composer.lock');

    $finding->update(['acknowledged_at' => now()]);
    $isRemoved = true;
    $this->travel(1)->day();
    artisan('psm:sync-lockfiles')->assertSuccessful();

    expect($finding->fresh())
        ->state->toBe(FindingState::Open)
        ->acknowledged_at->not->toBeNull()
        ->lockfilePaths()->toBe(['composer.lock'])
        ->and(Finding::where('rule', FindingRule::DependencyVulnerable)->open()->count())->toBe(4)
        ->and(chatMessagesTo('COMMERCE'))->toHaveCount(1);
});
