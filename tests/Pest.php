<?php

use App\Actions\SyncTechnologyCatalog;
use App\Models\Project;
use App\Models\Runtime;
use App\Models\Service;
use App\Models\Team;
use App\Models\Technology;
use App\Models\User;
use App\Services\Notices\NodejsNoticeFeed;
use App\Services\Notices\PhpNetNoticeFeed;
use App\Services\Notices\RubyLangNoticeFeed;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

use function Pest\Laravel\actingAs;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(LazilyRefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Sign in as a new admin, the only role that may use the panel in the MVP.
 */
function actingAsAdmin(): User
{
    $admin = User::factory()->admin()->create();

    actingAs($admin);

    return $admin;
}

/**
 * Fakes endoflife.date and the official release feeds with tests/Fixtures/catalog: real responses from 2026-10-23,
 * trimmed to a few branches. Node.js 24 is cut at 24.22.0, so its latest release is not a security release.
 */
function fakeCatalogSources(): void
{
    $fixture = fn (string $name): string => (string) file_get_contents(base_path("tests/Fixtures/catalog/{$name}"));

    Http::preventStrayRequests();
    Http::fake([
        'https://endoflife.date/api/v1/products/php/' => Http::response($fixture('endoflife-php.json')),
        'https://endoflife.date/api/v1/products/nodejs/' => Http::response($fixture('endoflife-nodejs.json')),
        'https://endoflife.date/api/v1/products/ruby/' => Http::response($fixture('endoflife-ruby.json')),
        'https://endoflife.date/api/v1/products/python/' => Http::response($fixture('endoflife-python.json')),
        'https://www.php.net/releases/*' => Http::response($fixture('php-releases-8.json')),
        'https://nodejs.org/dist/index.json' => Http::response($fixture('nodejs-index.json')),
        'https://cache.ruby-lang.org/pub/ruby/index.txt' => Http::response($fixture('ruby-index.txt')),
    ]);
}

/**
 * A technology whose catalog was just synced from the fixtures: "php", "nodejs", "ruby", or "python" (endoflife.date only).
 */
function syncedTechnology(string $slug): Technology
{
    fakeCatalogSources();

    $technology = match ($slug) {
        'php' => Technology::factory()->php()->create(),
        'nodejs' => Technology::factory()->nodejs()->create(),
        'ruby' => Technology::factory()->ruby()->create(),
        'python' => Technology::factory()->create(['name' => 'Python', 'slug' => 'python', 'endoflife_product' => 'python']),
    };

    app(SyncTechnologyCatalog::class)->handle($technology);

    return $technology;
}

/**
 * The webhook of a Google Chat space in tests, e.g. "COMMERCE" or "ADMIN".
 */
function chatWebhook(string $space): string
{
    return Team::GOOGLE_CHAT_WEBHOOK_PREFIX.$space.'/messages?key=demo-key&token=demo-token';
}

/**
 * Fakes Google Chat: every space accepts messages, and the admin space is configured as "ADMIN".
 */
function fakeGoogleChat(): void
{
    config(['psm.alerts.admin_webhook' => chatWebhook('ADMIN')]);

    Http::preventStrayRequests();
    Http::fake([Team::GOOGLE_CHAT_WEBHOOK_PREFIX.'*' => Http::response(['name' => 'spaces/DEMO/messages/1'])]);
}

/**
 * Messages posted to a Google Chat space, oldest first.
 *
 * @return Collection<int, array<string, mixed>>
 */
function chatMessagesTo(string $space): Collection
{
    return Http::recorded(fn (Request $request): bool => $request->url() === chatWebhook($space))
        ->map(fn (array $pair): array => $pair[0]->data())
        ->values();
}

/**
 * A Google Chat message as readable JSON, for asserting on the text it contains.
 *
 * @param  array<mixed>|Collection<int, mixed>  $message
 */
function chatJson(array|Collection $message): string
{
    return (string) json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * A vendor feed from tests/Fixtures/notices: the real feeds fetched on 2026-10-23, trimmed to a few entries. php.net
 * keeps its "Upcoming Critical PHP Security Release" of 2026-10-20 verbatim. With $asOf, entries published later are
 * left out, so the feed reads as it did at that time.
 */
function noticeFixture(string $name, ?string $asOf = null): string
{
    $xml = (string) file_get_contents(base_path("tests/Fixtures/notices/{$name}"));

    if ($asOf === null) {
        return $xml;
    }

    $document = new DOMDocument;
    $document->loadXML($xml, LIBXML_NONET);
    $xpath = new DOMXPath($document);
    $xpath->registerNamespace('a', 'http://www.w3.org/2005/Atom');

    foreach (iterator_to_array($xpath->query('/a:feed/a:entry | /rss/channel/item')) as $entry) {
        $published = $xpath->evaluate('string(a:published | pubDate)', $entry);

        if (CarbonImmutable::parse($published)->isAfter(CarbonImmutable::parse($asOf))) {
            $entry->parentNode->removeChild($entry);
        }
    }

    return (string) $document->saveXML();
}

/**
 * Fakes the php.net, nodejs.org and ruby-lang.org security feeds with their fixtures. Each response reads as the feed
 * did at the current (travelled) time: entries published later are left out.
 */
function fakeNoticeFeeds(): void
{
    $feed = fn (string $name): Closure => fn () => Http::response(noticeFixture($name, now()->toIso8601String()));

    Http::preventStrayRequests();
    Http::fake([
        PhpNetNoticeFeed::URL => $feed('php-feed.atom'),
        NodejsNoticeFeed::URL => $feed('nodejs-vulnerability.xml'),
        RubyLangNoticeFeed::URL => $feed('ruby-news.rss'),
    ]);
}

/**
 * A runtime of a team's service, on the service's current production deployment.
 */
function runtimeAt(Team $team, string $service, Technology $technology, string $version): Runtime
{
    $service = Service::where('name', $service)->first()
        ?? Service::factory()->for(Project::factory()->for($team))->create(['name' => $service, 'slug' => $service]);

    $deployment = $service->productionDeployment ?? $service->deployments()->create(['environment' => 'production']);

    return $deployment->runtimes()->create(['technology_id' => $technology->id, 'raw_version' => $version, 'source' => 'manual', 'observed_at' => now()]);
}

/**
 * A lockfile from tests/Fixtures/lockfiles: small made-up projects pinning real package versions, some with known
 * vulnerabilities, some clean.
 */
function lockfileFixture(string $name): string
{
    return (string) file_get_contents(base_path("tests/Fixtures/lockfiles/{$name}"));
}

/**
 * Fakes the OSV API with tests/Fixtures/osv: the real `querybatch` and `vulns/{id}` responses of 2026-10-23 for the
 * packages of tests/Fixtures/lockfiles. A package version the fixtures do not cover has no vulnerabilities. With
 * $isPublished, vulnerabilities it rejects are not listed yet, as before OSV published them.
 *
 * @param  (Closure(string): bool)|null  $isPublished
 */
function fakeOsv(?Closure $isPublished = null): void
{
    $known = [];

    foreach (glob(base_path('tests/Fixtures/osv/querybatch-*.request.json')) ?: [] as $requestFile) {
        $queries = json_decode((string) file_get_contents($requestFile), true)['queries'];
        $results = json_decode((string) file_get_contents(str_replace('.request.json', '.json', $requestFile)), true)['results'];

        foreach ($queries as $index => $query) {
            $known["{$query['package']['ecosystem']}|{$query['package']['name']}|{$query['version']}"] = $results[$index]['vulns'] ?? [];
        }
    }

    Http::preventStrayRequests();
    Http::fake([
        'https://api.osv.dev/v1/querybatch' => fn (Request $request) => Http::response(['results' => array_map(function (array $query) use ($known, $isPublished): object {
            $vulns = array_values(array_filter(
                $known["{$query['package']['ecosystem']}|{$query['package']['name']}|{$query['version']}"] ?? [],
                fn (array $vuln): bool => $isPublished === null || $isPublished($vuln['id']),
            ));

            return (object) ($vulns === [] ? [] : ['vulns' => $vulns]);
        }, $request->data()['queries'])]),
        'https://api.osv.dev/v1/vulns/*' => function (Request $request) {
            $file = base_path('tests/Fixtures/osv/vulns/'.basename(parse_url($request->url(), PHP_URL_PATH)).'.json');

            return is_file($file) ? Http::response((string) file_get_contents($file)) : Http::response(['code' => 5, 'message' => 'Bug not found.'], 404);
        },
    ]);
}

/**
 * Requests sent to the OSV API, oldest first.
 *
 * @return Collection<int, Request>
 */
function osvRequests(string $path = ''): Collection
{
    return Http::recorded(fn (Request $request): bool => str_starts_with($request->url(), 'https://api.osv.dev/v1/'.$path))
        ->map(fn (array $pair): Request => $pair[0])
        ->values();
}

/**
 * Makes the OSV API answer 503 while $failing is true. Register it before fakeOsv(): the first fake that answers wins.
 */
function fakeOsvOutage(bool &$failing): void
{
    Http::fake(['https://api.osv.dev/*' => function () use (&$failing) {
        return $failing ? Http::response('Service Unavailable', 503) : null;
    }]);
}
