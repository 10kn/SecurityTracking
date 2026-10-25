<?php

use App\Services\Repositories\GitHubRepository;
use App\Services\Repositories\GitHubRepositoryReader;
use App\Services\Repositories\RepositoryNotFoundException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

const SHOP_REPOSITORY = 'https://github.com/acme/shop';

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
    $reader->filesIn(SHOP_REPOSITORY, '', GITHUB_COMMIT);

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

it('lists the files of a directory at the commit, without subdirectories', function () {
    fakeGitHub(['apps/api/composer.lock' => '{"packages":[]}', 'apps/api/src/Kernel.php' => '<?php', 'package-lock.json' => '{}']);
    $reader = app(GitHubRepositoryReader::class);

    expect($reader->filesIn(SHOP_REPOSITORY, 'apps/api', GITHUB_COMMIT))->toBe(['composer.lock' => 15])
        ->and($reader->filesIn(SHOP_REPOSITORY, '', GITHUB_COMMIT))->toBe(['package-lock.json' => 2])
        ->and($reader->read(SHOP_REPOSITORY, 'apps/api/composer.lock', GITHUB_COMMIT))->toBe('{"packages":[]}');

    expect(gitHubRequests('repos/acme/shop/contents/apps/api?ref='.GITHUB_COMMIT))->toHaveCount(1)
        ->and(gitHubRequests('repos/acme/shop/contents?ref='.GITHUB_COMMIT))->toHaveCount(1);
});

it('reports a missing repository, branch or directory without retrying', function () {
    fakeGitHub();

    expect(fn () => app(GitHubRepositoryReader::class)->filesIn(SHOP_REPOSITORY, 'apps/api', GITHUB_COMMIT))->toThrow(RepositoryNotFoundException::class);

    expect(gitHubRequests('repos/'))->toHaveCount(1);
});

it('rejects a directory that climbs out of the repository', function () {
    fakeGitHub();

    expect(fn () => app(GitHubRepositoryReader::class)->filesIn(SHOP_REPOSITORY, 'apps/../..', GITHUB_COMMIT))->toThrow(InvalidArgumentException::class);

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

it('reads github repository urls', function (string $url, ?array $repository) {
    $parsed = GitHubRepository::fromUrl($url);

    expect($parsed === null ? null : [$parsed->owner, $parsed->name])->toBe($repository);
})->with([
    'https' => ['https://github.com/acme/shop', ['acme', 'shop']],
    'https with .git' => ['https://github.com/acme/shop.git', ['acme', 'shop']],
    'trailing slash' => ['https://github.com/acme/shop-api/', ['acme', 'shop-api']],
    'ssh' => ['git@github.com:acme/shop.git', ['acme', 'shop']],
    'dots in the name' => ['https://github.com/acme/shop.web', ['acme', 'shop.web']],
    'another host' => ['https://git.example.com/acme/shop', null],
    'inside the repository' => ['https://github.com/acme/shop/tree/main', null],
    'parent directory' => ['https://github.com/acme/..', null],
]);
