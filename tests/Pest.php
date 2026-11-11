<?php

use App\Actions\SyncTechnologyCatalog;
use App\Enums\IncidentImpact;
use App\Enums\Severity;
use App\Enums\Technology;
use App\Models\Incident;
use App\Models\IncidentTeam;
use App\Models\Project;
use App\Models\Runtime;
use App\Models\Service;
use App\Models\Team;
use App\Models\User;
use App\Services\IncidentTeamExposure;
use App\Services\Notices\NodejsNoticeFeed;
use App\Services\Notices\NoticeClassification;
use App\Services\Notices\NoticeClassifier;
use App\Services\Notices\NoticeEntry;
use App\Services\Notices\PhpNetNoticeFeed;
use App\Services\Notices\RubyLangNoticeFeed;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
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
 * An admin's assessment of the incident for the team ("Đã đọc"), made on the team's exposure as it is now.
 */
function assessIncident(Incident $incident, Team $team, IncidentImpact $impact = IncidentImpact::Yes, ?User $admin = null, ?string $note = null): IncidentTeam
{
    [$row] = IncidentTeam::lockFor($incident, $team);
    $row->acknowledge($admin ?? User::factory()->admin()->create(), $impact, $note, IncidentTeamExposure::for($incident, $team));

    return $row->refresh();
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
        'https://www.php.net/releases/*' => Http::response($fixture('php-releases-8.json')),
        'https://nodejs.org/dist/index.json' => Http::response($fixture('nodejs-index.json')),
        'https://cache.ruby-lang.org/pub/ruby/index.txt' => Http::response($fixture('ruby-index.txt')),
    ]);
}

/**
 * A technology whose catalog was just synced from the fixtures.
 */
function syncedTechnology(Technology $technology): Technology
{
    fakeCatalogSources();

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
 * Fakes Google Chat: every space accepts messages and answers as Google does, with the message's name and its
 * thread's, one thread per thread key (a message without one opens its own thread). The admin space is configured as
 * "ADMIN", and the security news space as "NEWS".
 */
function fakeGoogleChat(): void
{
    config(['psm.alerts.admin_webhook' => chatWebhook('ADMIN'), 'psm.alerts.news_webhook' => chatWebhook('NEWS')]);

    Http::preventStrayRequests();
    Http::fake([Team::GOOGLE_CHAT_WEBHOOK_PREFIX.'*' => function (Request $request) {
        static $sequence = 0;
        $sequence++;
        $space = Str::between($request->url(), Team::GOOGLE_CHAT_WEBHOOK_PREFIX, '/messages');
        $threadKey = $request->data()['thread']['threadKey'] ?? null;

        return Http::response([
            'name' => "spaces/{$space}/messages/m{$sequence}",
            'thread' => ['name' => "spaces/{$space}/threads/".($threadKey === null ? "t{$sequence}" : substr(md5($threadKey), 0, 12))],
        ]);
    }]);
}

/**
 * A Google Chat webhook URL without the query parameter a message of a thread adds to it (`messageReplyOption`).
 */
function chatWebhookOf(Request $request): string
{
    return (string) preg_replace('/&messageReplyOption=[^&]*/', '', $request->url());
}

/**
 * Requests posted to a Google Chat space, oldest first.
 *
 * @return Collection<int, Request>
 */
function chatRequestsTo(string $space): Collection
{
    return Http::recorded(fn (Request $request): bool => chatWebhookOf($request) === chatWebhook($space))
        ->map(fn (array $pair): Request => $pair[0])
        ->values();
}

/**
 * Messages posted to a Google Chat space, oldest first.
 *
 * @return Collection<int, array<string, mixed>>
 */
function chatMessagesTo(string $space): Collection
{
    return chatRequestsTo($space)->map(fn (Request $request): array => $request->data())->values();
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
 * did at the current (travelled) time: entries published later are left out. The nodejs.org posts the feed lists by
 * title only are served from tests/Fixtures/notices/nodejs-posts (see nodejsPostFixture()).
 */
function fakeNoticeFeeds(): void
{
    $feed = fn (string $name): Closure => fn () => Http::response(noticeFixture($name, now()->toIso8601String()));

    Http::preventStrayRequests();
    Http::fake([
        PhpNetNoticeFeed::URL => $feed('php-feed.atom'),
        NodejsNoticeFeed::URL => $feed('nodejs-vulnerability.xml'),
        NodejsNoticeFeed::POSTS.'*' => fn (Request $request) => Http::response(nodejsPostFixture($request->url()) ?? 'Not Found', nodejsPostFixture($request->url()) === null ? 404 : 200),
        RubyLangNoticeFeed::URL => $feed('ruby-news.rss'),
    ]);
}

/**
 * A nodejs.org vulnerability post from tests/Fixtures/notices/nodejs-posts, by its address: the real pages fetched on
 * 2026-11-09, the October one with the details of the releases, trimmed to their `<main>`. Null for another post.
 */
function nodejsPostFixture(string $url): ?string
{
    $path = base_path('tests/Fixtures/notices/nodejs-posts/'.basename((string) parse_url($url, PHP_URL_PATH)).'.html');

    return is_file($path) ? (string) file_get_contents($path) : null;
}

/**
 * Fakes the CISA KEV catalog with tests/Fixtures/kev: 6 real entries of catalog 2026.10.26 (the PHP RCEs CISA added on
 * 2026-10-26, Livewire, Vite, jQuery and Ignition). `$change` may edit an entry or drop it by returning null. Calling
 * it again, or fakeKevResponse(), changes what the catalog answers from then on.
 *
 * @param  (Closure(array<string, mixed>): ?array<string, mixed>)|null  $change
 */
function fakeKevCatalog(?Closure $change = null): void
{
    $catalog = json_decode((string) file_get_contents(base_path('tests/Fixtures/kev/known_exploited_vulnerabilities.json')), true);

    if ($change !== null) {
        $catalog['vulnerabilities'] = array_values(array_filter(array_map($change, $catalog['vulnerabilities'])));
        $catalog['count'] = count($catalog['vulnerabilities']);
    }

    fakeKevResponse($catalog);
}

/**
 * Makes the CISA KEV catalog URL answer `$body` with `$status` from now on. The GitHub advisory database knows no GHSA
 * id, unless a test fakes it first.
 *
 * @param  array<string, mixed>|string  $body
 */
function fakeKevResponse(array|string $body, int $status = 200): void
{
    $isFaked = app()->bound('tests.kev');
    app()->instance('tests.kev', [$body, $status]);

    if (! $isFaked) {
        Http::fake([
            config('psm.kev.url') => fn () => Http::response(...app('tests.kev')),
            rtrim((string) config('psm.kev.ghsa_api_url'), '/').'/*' => Http::response(['message' => 'Not Found'], 404),
        ]);
    }
}

/**
 * A runtime of a team's service, on the service's current production deployment.
 */
function runtimeAt(Team $team, string $service, Technology $technology, string $version): Runtime
{
    $service = Service::where('name', $service)->first()
        ?? Service::factory()->for(Project::factory()->for($team))->create(['name' => $service, 'slug' => $service]);

    $deployment = $service->productionDeployment ?? $service->deployments()->create(['environment' => 'production']);

    return $deployment->runtimes()->create(['technology' => $technology, 'raw_version' => $version, 'source' => 'manual', 'observed_at' => now()]);
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
 * packages of tests/Fixtures/lockfiles, and of 2026-11-11 for three GitHub Actions, asked without a version (OSV cannot
 * compare their versions). A package version the fixtures do not cover has no vulnerabilities. With
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
            $known["{$query['package']['ecosystem']}|{$query['package']['name']}|".($query['version'] ?? '')] = $results[$index]['vulns'] ?? [];
        }
    }

    Http::preventStrayRequests();
    Http::fake([
        'https://api.osv.dev/v1/querybatch' => fn (Request $request) => Http::response(['results' => array_map(function (array $query) use ($known, $isPublished): object {
            $vulns = array_values(array_filter(
                $known["{$query['package']['ecosystem']}|{$query['package']['name']}|".($query['version'] ?? '')] ?? [],
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
 * Fakes the Packagist security advisories API with tests/Fixtures/packagist: the real response of 2026-10-24 for the
 * packages of tests/Fixtures/lockfiles/composer.lock. Other packages have no advisories. With $isPublished, an advisory
 * it rejects any id of (PKSA, GitHub, CVE) is not listed yet.
 *
 * @param  (Closure(string): bool)|null  $isPublished
 */
function fakePackagist(?Closure $isPublished = null): void
{
    $known = json_decode((string) file_get_contents(base_path('tests/Fixtures/packagist/security-advisories.json')), true)['advisories'];

    Http::preventStrayRequests();
    Http::fake([
        'https://packagist.org/api/security-advisories/' => fn (Request $request) => Http::response(['advisories' => collect($request->data()['packages'] ?? [])
            ->mapWithKeys(fn (string $name): array => [$name => array_values(array_filter(
                $known[$name] ?? [],
                fn (array $advisory): bool => $isPublished === null || collect([$advisory['advisoryId'], $advisory['cve'], ...array_column($advisory['sources'], 'remoteId')])->filter()->every(fn (string $id): bool => $isPublished($id)),
            ))])
            ->all()]),
    ]);
}

/**
 * Makes the Packagist API answer 503 while $failing is true. Register it before fakePackagist().
 */
function fakePackagistOutage(bool &$failing): void
{
    Http::fake(['https://packagist.org/*' => function () use (&$failing) {
        return $failing ? Http::response('Service Unavailable', 503) : null;
    }]);
}

/**
 * Requests sent to the Packagist API, oldest first.
 *
 * @return Collection<int, Request>
 */
function packagistRequests(): Collection
{
    return Http::recorded(fn (Request $request): bool => str_starts_with($request->url(), 'https://packagist.org/'))
        ->map(fn (array $pair): Request => $pair[0])
        ->values();
}

/**
 * Fakes the RubySec advisory database with tests/Fixtures/rubysec/ruby-advisory-db-master.tar.gz: the real advisories
 * of 2026-10-25 for the gems of tests/Fixtures/lockfiles/Gemfile.lock, and CVE-2016-6316, filed under both actionpack
 * and actionview, packed by `git archive` as GitHub packs the tarball of a branch.
 */
function fakeRubySec(): void
{
    Http::preventStrayRequests();
    Http::fake(['https://github.com/rubysec/ruby-advisory-db/archive/*' => Http::response((string) file_get_contents(base_path('tests/Fixtures/rubysec/ruby-advisory-db-master.tar.gz')))]);
}

/**
 * Requests sent for the RubySec advisory database, oldest first.
 *
 * @return Collection<int, Request>
 */
function rubySecRequests(): Collection
{
    return Http::recorded(fn (Request $request): bool => str_starts_with($request->url(), 'https://github.com/rubysec/'))
        ->map(fn (array $pair): Request => $pair[0])
        ->values();
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

/**
 * The commit every branch of every faked GitHub repository points at.
 */
const GITHUB_COMMIT = '3f786850e387550fdab836ed7e6dc881de23001b';

/**
 * Configures the GitHub App with a private key made for the test run and returns the PEM of its public key.
 */
function configureGitHubApp(): string
{
    static $keys = null;

    if ($keys === null) {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $privateKey);
        $path = tempnam(sys_get_temp_dir(), 'psm-github-app-');
        file_put_contents($path, $privateKey);
        $keys = ['path' => $path, 'public' => openssl_pkey_get_details($key)['key']];
    }

    config([
        'psm.github.app_id' => '12345',
        'psm.github.installation_id' => '678',
        'psm.github.private_key_path' => $keys['path'],
    ]);

    return $keys['public'];
}

/**
 * Configures the GitHub App and fakes the GitHub API: an installation token, every branch at $commitSha, and the
 * files of every repository at that commit, by path. Directories are read from the paths; a commit names the root
 * tree and fakeTreeSha() the others. Recursive listings of the directories in $truncatedTrees are cut short, as GitHub
 * does above 100,000 entries. Anything else is 404, as on GitHub. Register outages and other fakes before it: the
 * first fake that answers wins.
 *
 * @param  array<string, string>  $files
 * @param  list<string>  $truncatedTrees  Directories by path, "" for the root.
 */
function fakeGitHub(array $files = [], string $commitSha = GITHUB_COMMIT, array $truncatedTrees = []): void
{
    configureGitHubApp();

    Http::preventStrayRequests();
    Http::fake([
        'https://api.github.com/app/installations/*/access_tokens' => Http::response(['token' => 'ghs_test', 'expires_at' => now()->addHour()->toIso8601String()], 201),
        'https://api.github.com/repos/*/commits*' => Http::response([['sha' => $commitSha]]),
        'https://api.github.com/repos/*/git/trees/*' => function (Request $request) use ($files, $truncatedTrees) {
            $directories = collect($files)->keys()
                ->flatMap(function (string $file): array {
                    $segments = explode('/', $file);

                    return count($segments) < 2 ? [] : array_map(fn (int $length): string => implode('/', array_slice($segments, 0, $length)), range(1, count($segments) - 1));
                })
                ->filter()
                ->unique();
            $treeSha = rawurldecode(Str::afterLast((string) parse_url($request->url(), PHP_URL_PATH), '/'));
            // Any other SHA is a commit, which names the root tree.
            $directory = $directories->first(fn (string $directory): bool => fakeTreeSha($directory) === $treeSha) ?? '';
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $isRecursive = array_key_exists('recursive', $query);
            $prefix = $directory === '' ? '' : "{$directory}/";
            $isListed = fn (string $path): bool => str_starts_with($path, $prefix) && ($isRecursive || ! str_contains(substr($path, strlen($prefix)), '/'));

            $entries = [
                ...$directories->filter($isListed)->map(fn (string $path): array => ['path' => substr($path, strlen($prefix)), 'mode' => '040000', 'type' => 'tree', 'sha' => fakeTreeSha($path)]),
                ...collect($files)->filter(fn (string $content, string $path): bool => $isListed($path))
                    ->map(fn (string $content, string $path): array => ['path' => substr($path, strlen($prefix)), 'mode' => '100644', 'type' => 'blob', 'sha' => sha1($content), 'size' => strlen($content)]),
            ];
            $isTruncated = $isRecursive && in_array($directory, $truncatedTrees, true);

            return Http::response(['sha' => $treeSha, 'tree' => $isTruncated ? array_slice(array_values($entries), 0, 1) : array_values($entries), 'truncated' => $isTruncated]);
        },
        'https://api.github.com/repos/*/contents*' => function (Request $request) use ($files) {
            $path = trim(rawurldecode((string) preg_replace('#^/repos/[^/]+/[^/]+/contents#', '', (string) parse_url($request->url(), PHP_URL_PATH))), '/');

            if (str_contains($request->header('Accept')[0] ?? '', 'raw')) {
                return array_key_exists($path, $files) ? Http::response($files[$path]) : Http::response(['message' => 'Not Found'], 404);
            }

            $entries = collect($files)
                ->keys()
                ->filter(fn (string $file): bool => $path === '' || str_starts_with($file, "{$path}/"))
                ->map(fn (string $file): string => explode('/', ltrim(substr($file, strlen($path)), '/'))[0])
                ->unique()
                ->map(fn (string $name): array => [
                    'name' => $name,
                    'path' => ltrim("{$path}/{$name}", '/'),
                    'type' => array_key_exists(ltrim("{$path}/{$name}", '/'), $files) ? 'file' : 'dir',
                    'size' => strlen($files[ltrim("{$path}/{$name}", '/')] ?? ''),
                ])
                ->values();

            return $entries->isEmpty() && $path !== '' ? Http::response(['message' => 'Not Found'], 404) : Http::response($entries->all());
        },
    ]);
}

/**
 * The tree SHA fakeGitHub() gives a directory, e.g. "apps/api".
 */
function fakeTreeSha(string $directory): string
{
    return sha1("tree:{$directory}");
}

/**
 * Requests sent to the GitHub API, oldest first.
 *
 * @return Collection<int, Request>
 */
function gitHubRequests(string $path = ''): Collection
{
    return Http::recorded(fn (Request $request): bool => str_starts_with($request->url(), 'https://api.github.com/'.$path))
        ->map(fn (array $pair): Request => $pair[0])
        ->values();
}

/**
 * Classifies every entry with `$answer`, which may throw, and keeps the titles of the entries it was given. `$answer`
 * also gets the incidents offered to the classifier. `$revision` is what the classifier gives as its revision.
 *
 * @param  Closure(NoticeEntry, list<array{id: int, title: string, published_at: ?CarbonInterface}>): NoticeClassification  $answer
 */
function classifyNoticesWith(Closure $answer, int $revision = 1): ArrayObject
{
    $titles = new ArrayObject;

    app()->instance(NoticeClassifier::class, new class($answer, $titles, $revision) implements NoticeClassifier
    {
        public function __construct(private Closure $answer, private ArrayObject $titles, private int $revision) {}

        public function revision(): int
        {
            return $this->revision;
        }

        public function isEnabled(): bool
        {
            return true;
        }

        public function name(): string
        {
            return 'claude-haiku-5-5';
        }

        public function classify(Technology $technology, NoticeEntry $entry, array $incidents = []): NoticeClassification
        {
            $this->titles[] = $entry->title;

            return ($this->answer)($entry, $incidents);
        }
    });

    return $titles;
}

/**
 * Classifies the entries of tests/Fixtures/notices as the classifier reads them: whether each is security news, the
 * vendor's rating and the versions to update to. Any other entry is not security news. `$answers` replaces the answer
 * for a title.
 *
 * @param  array<string, NoticeClassification>  $answers
 */
function classifyNoticeFixtures(array $answers = []): ArrayObject
{
    $security = fn (Severity $severity = Severity::Unknown, array $patchVersions = []): NoticeClassification => new NoticeClassification(isSecurityNews: true, severity: $severity, patchVersions: $patchVersions);
    $answers += [
        'Security advisory: Critical remote code execution vulnerabilities, update now' => $security(Severity::Critical, ['8.5.12', '8.4.27', '8.3.36', '8.2.35']),
        'PHP 8.3.36 Released!' => $security(Severity::Critical, ['8.3.36']),
        'Upcoming Critical PHP Security Release' => $security(Severity::Critical),
        'PHP 8.3.35 Released!' => $security(patchVersions: ['8.3.35']),
        'Wednesday, October 21, 2026 Security Releases' => $security(),
        'Wednesday, July 29, 2026 Security Releases' => $security(),
        'Developing a minimally HashDoS resistant, yet quickly reversible integer hash for V8' => $security(),
        'Ruby 3.3.13 Released' => $security(patchVersions: ['3.3.13']),
        "Regexp compilation fixes related to GitLab's CVE-2026-89078, CVE-2026-93577, CVE-2026-97229 and CVE-2026-110264" => $security(patchVersions: ['4.0.9', '3.4.12', '3.3.13']),
        'Security advisories: CVE-2026-80212 and CVE-2026-80213' => $security(),
    ];

    return classifyNoticesWith(fn (NoticeEntry $entry): NoticeClassification => $answers[$entry->title] ?? new NoticeClassification(isSecurityNews: false, severity: Severity::Unknown));
}
