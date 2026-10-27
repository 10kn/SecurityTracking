<?php

use App\Services\Repositories\GitHubRepository;
use App\Services\Repositories\GitHubRepositoryReader;
use App\Services\Repositories\IncompleteListingException;
use App\Services\Repositories\RepositoryNotFoundException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

const SHOP_REPOSITORY = 'acme/shop';

/**
 * @return array<string, int>
 */
function findLockfiles(GitHubRepositoryReader $reader, string $directory, int $maxDepth = 4): array
{
    $files = $reader->findFiles(SHOP_REPOSITORY, $directory, GITHUB_COMMIT, ['composer.lock', 'package-lock.json'], $maxDepth, fn (string $path): bool => false);
    ksort($files);

    return $files;
}

beforeEach(function () {
    $this->travelTo('2026-10-24 06:30:00');
});

afterEach(function () {
    JWT::$timestamp = null;
});

it('signs in as the github app and reuses the installation token', function () {
    fakeGitHub(['composer.lock' => '{}']);
    $publicKey = configureGitHubApp();
    $reader = app(GitHubRepositoryReader::class);

    $reader->latestCommitSha(SHOP_REPOSITORY, null);
    findLockfiles($reader, '');

    $tokenRequests = gitHubRequests('app/installations/678/access_tokens');
    expect($tokenRequests)->toHaveCount(1)
        ->and($tokenRequests[0]->method())->toBe('POST')
        ->and($tokenRequests[0]->data())->toBe(['permissions' => ['contents' => 'read', 'metadata' => 'read']]);

    JWT::$timestamp = now()->getTimestamp();
    $claims = JWT::decode(substr($tokenRequests[0]->header('Authorization')[0], strlen('Bearer ')), new Key($publicKey, 'RS256'));
    expect($claims->iss)->toBe(12345)
        ->and($claims->exp - $claims->iat)->toBeLessThanOrEqual(600);

    expect(gitHubRequests('repos/')->map(fn (Request $request): string => $request->header('Authorization')[0])->unique()->all())->toBe(['Bearer ghs_test']);
});

it('asks for a new token shortly before the old one expires', function () {
    fakeGitHub();
    $reader = app(GitHubRepositoryReader::class);

    $reader->latestCommitSha(SHOP_REPOSITORY, null);
    $this->travel(56)->minutes();
    $reader->latestCommitSha(SHOP_REPOSITORY, null);

    expect(gitHubRequests('app/installations/'))->toHaveCount(2);
});

it('reads the tip of the branch, or of the default branch', function (?string $branch, array $query) {
    fakeGitHub();

    expect(app(GitHubRepositoryReader::class)->latestCommitSha(SHOP_REPOSITORY, $branch))->toBe(GITHUB_COMMIT);

    parse_str((string) parse_url(gitHubRequests('repos/acme/shop/commits')[0]->url(), PHP_URL_QUERY), $sent);
    expect($sent)->toBe($query);
})->with([
    'branch' => ['release/2026-10', ['sha' => 'release/2026-10', 'per_page' => '1']],
    'default branch' => [null, ['per_page' => '1']],
]);

it('finds the files with the given names in a directory and its subdirectories at the commit', function () {
    fakeGitHub(['apps/api/composer.lock' => '{"packages":[]}', 'apps/api/src/Kernel.php' => '<?php', 'apps/api/web/package-lock.json' => '{}', 'package-lock.json' => '{}']);
    $reader = app(GitHubRepositoryReader::class);

    expect(findLockfiles($reader, 'apps/api'))->toBe(['composer.lock' => 15, 'web/package-lock.json' => 2])
        ->and(findLockfiles($reader, '/apps/api/', maxDepth: 0))->toBe(['composer.lock' => 15])
        ->and(findLockfiles($reader, ''))->toBe(['apps/api/composer.lock' => 15, 'apps/api/web/package-lock.json' => 2, 'package-lock.json' => 2])
        ->and($reader->read(SHOP_REPOSITORY, 'apps/api/composer.lock', GITHUB_COMMIT))->toBe('{"packages":[]}');
});

it('leaves out links and submodules', function () {
    Http::fake(['https://api.github.com/repos/*/git/trees/*' => Http::response(['sha' => GITHUB_COMMIT, 'truncated' => false, 'tree' => [
        ['path' => 'composer.lock', 'mode' => '100644', 'type' => 'blob', 'sha' => sha1('a'), 'size' => 2],
        ['path' => 'web/package-lock.json', 'mode' => '120000', 'type' => 'blob', 'sha' => sha1('b'), 'size' => 20],
        ['path' => 'shared', 'mode' => '160000', 'type' => 'commit', 'sha' => sha1('c')],
    ]])]);
    fakeGitHub();

    expect(findLockfiles(app(GitHubRepositoryReader::class), ''))->toBe(['composer.lock' => 2]);
});

it('reports a missing repository, branch or directory without retrying', function () {
    fakeGitHub(['apps/web/composer.lock' => '{}']);

    expect(fn () => findLockfiles(app(GitHubRepositoryReader::class), 'apps/api'))->toThrow(RepositoryNotFoundException::class);

    expect(gitHubRequests('repos/'))->toHaveCount(2);
});

it('does not take a directory missing from a level GitHub cut short for a missing one', function () {
    Http::fake(['https://api.github.com/repos/*/git/trees/'.GITHUB_COMMIT => Http::response(['sha' => GITHUB_COMMIT, 'tree' => [], 'truncated' => true])]);
    fakeGitHub(['apps/api/composer.lock' => '{}']);

    expect(fn () => findLockfiles(app(GitHubRepositoryReader::class), 'apps/api'))->toThrow(IncompleteListingException::class);
});

it('rejects a directory that climbs out of the repository', function () {
    fakeGitHub();

    expect(fn () => findLockfiles(app(GitHubRepositoryReader::class), 'apps/../..'))->toThrow(InvalidArgumentException::class);

    expect(gitHubRequests('repos/'))->toHaveCount(0);
});

it('retries a github outage before giving up', function () {
    Sleep::fake();
    Http::fake(['https://api.github.com/repos/*' => Http::response('Service Unavailable', 503)]);
    fakeGitHub();

    expect(fn () => app(GitHubRepositoryReader::class)->latestCommitSha(SHOP_REPOSITORY, null))->toThrow(RequestException::class);

    expect(gitHubRequests('repos/'))->toHaveCount(2);
});

it('is not configured without the app id, installation id and private key', function () {
    Http::preventStrayRequests();
    config(['psm.github.app_id' => null, 'psm.github.installation_id' => '678', 'psm.github.private_key_path' => '/tmp/key.pem']);
    $reader = app(GitHubRepositoryReader::class);

    expect($reader->isConfigured())->toBeFalse()
        ->and(fn () => $reader->latestCommitSha(SHOP_REPOSITORY, null))->toThrow(RuntimeException::class);
});

it('lists every repository the app was installed on, page by page', function () {
    $page = fn (int $from, int $count): array => array_map(fn (int $number): array => [
        'full_name' => "acme/repo-{$number}",
        'default_branch' => 'main',
        'private' => $number % 2 === 0,
    ], range($from, $from + $count - 1));
    Http::fake([
        'https://api.github.com/installation/repositories?per_page=100&page=1' => Http::response(['total_count' => 101, 'repositories' => $page(1, 100)]),
        'https://api.github.com/installation/repositories?per_page=100&page=2' => Http::response(['total_count' => 101, 'repositories' => [['full_name' => 'acme/shop', 'default_branch' => 'develop', 'private' => true]]]),
    ]);
    fakeGitHub();

    $repositories = app(GitHubRepositoryReader::class)->repositories();

    expect($repositories)->toHaveCount(101)
        ->and($repositories[0])->toBe(['full_name' => 'acme/repo-1', 'default_branch' => 'main', 'is_private' => false])
        ->and($repositories[100])->toBe(['full_name' => 'acme/shop', 'default_branch' => 'develop', 'is_private' => true])
        ->and(gitHubRequests('installation/repositories'))->toHaveCount(2);
});

it('checks whether a branch exists', function () {
    Http::fake([
        'https://api.github.com/repos/acme/shop/branches/release%2F2026-10' => Http::response(['name' => 'release/2026-10']),
        'https://api.github.com/repos/acme/shop/branches/*' => Http::response(['message' => 'Branch not found'], 404),
    ]);
    fakeGitHub();
    $reader = app(GitHubRepositoryReader::class);

    expect($reader->branchExists(SHOP_REPOSITORY, 'release/2026-10'))->toBeTrue()
        ->and($reader->branchExists(SHOP_REPOSITORY, 'gone'))->toBeFalse();
});

it('links to a commit on github', function () {
    expect(app(GitHubRepositoryReader::class)->commitUrl(SHOP_REPOSITORY, GITHUB_COMMIT))->toBe('https://github.com/acme/shop/commit/'.GITHUB_COMMIT)
        ->and(app(GitHubRepositoryReader::class)->commitUrl('not a repository', GITHUB_COMMIT))->toBeNull();
});

it('reads repository full names', function (string $fullName, ?array $repository) {
    $parsed = GitHubRepository::fromFullName($fullName);

    expect($parsed === null ? null : [$parsed->owner, $parsed->name])->toBe($repository);
})->with([
    'owner and name' => ['acme/shop', ['acme', 'shop']],
    'dots in the name' => ['acme/shop.web', ['acme', 'shop.web']],
    'url' => ['https://github.com/acme/shop', null],
    'nested path' => ['acme/shop/tree', null],
    'parent directory' => ['acme/..', null],
]);
