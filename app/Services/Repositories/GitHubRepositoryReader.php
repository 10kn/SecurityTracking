<?php

namespace App\Services\Repositories;

use App\Enums\LockfileSource;
use App\Services\Catalog\CatalogHttp;
use Closure;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;
use Throwable;
use UnexpectedValueException;

/**
 * Lists and reads repositories through the GitHub REST API (https://docs.github.com/en/rest), signed in as the GitHub
 * App of `psm.github`, which may only read contents and metadata.
 */
class GitHubRepositoryReader implements RepositoryReader
{
    public const string API_VERSION = '2022-11-28';

    /**
     * The largest page GitHub serves.
     */
    private const int PER_PAGE = 100;

    public function __construct(private GitHubAppAuthenticator $authenticator) {}

    public function sourceKey(): string
    {
        return 'github';
    }

    public function sourceName(): string
    {
        return 'GitHub';
    }

    public function lockfileSource(): LockfileSource
    {
        return LockfileSource::GitHub;
    }

    public function isConfigured(): bool
    {
        return $this->authenticator->isConfigured();
    }

    /**
     * The repositories the App was installed on, page by page.
     */
    public function repositories(): array
    {
        $repositories = [];

        for ($page = 1; ; $page++) {
            $response = $this->get('installation/repositories', ['per_page' => self::PER_PAGE, 'page' => $page])->json();
            $entries = is_array($response) && is_array($response['repositories'] ?? null) ? $response['repositories'] : null;

            if ($entries === null) {
                throw new UnexpectedValueException('GitHub did not return the repositories of the installation.');
            }

            foreach ($entries as $entry) {
                if (is_array($entry) && is_string($entry['full_name'] ?? null)) {
                    $repositories[] = [
                        'full_name' => $entry['full_name'],
                        'default_branch' => is_string($entry['default_branch'] ?? null) ? $entry['default_branch'] : null,
                        'is_private' => (bool) ($entry['private'] ?? false),
                    ];
                }
            }

            if (count($entries) < self::PER_PAGE || count($repositories) >= (int) ($response['total_count'] ?? 0)) {
                return $repositories;
            }
        }
    }

    public function branchExists(string $repository, string $branch): bool
    {
        try {
            // A name such as "release/2026-10" is one path segment, so its slash is encoded.
            $this->get($this->repositoryPath($repository).'/branches/'.rawurlencode($branch));
        } catch (RepositoryNotFoundException) {
            return false;
        }

        return true;
    }

    public function latestCommitSha(string $repository, ?string $branch): string
    {
        $commits = $this->get($this->repositoryPath($repository).'/commits', array_filter(['sha' => $branch, 'per_page' => 1]))->json();
        $sha = is_array($commits) ? ($commits[0]['sha'] ?? null) : null;

        if (! is_string($sha) || preg_match('/^[0-9a-f]{40}$/', $sha) !== 1) {
            throw new UnexpectedValueException('GitHub did not return the latest commit.');
        }

        return $sha;
    }

    /**
     * The directory is found by walking the trees of the commit from its root (https://docs.github.com/en/rest/git/trees),
     * then listed in one recursive request. GitHub cuts a recursive listing short above 100,000 entries or 7 MB; the
     * directory is then listed level by level instead, without entering excluded directories. Every tree request counts
     * against `psm.github.lockfile_search.max_tree_requests`.
     */
    public function findFiles(string $repository, string $directory, string $commitSha, array $names, int $maxDepth, Closure $isExcluded): array
    {
        $budget = (int) config('psm.github.lockfile_search.max_tree_requests');
        $treeSha = $this->treeSha($repository, $directory, $commitSha, $budget);
        $tree = $this->tree($repository, $treeSha, true, $budget);

        if (! $tree['truncated']) {
            $files = [];

            foreach ($tree['entries'] as $entry) {
                $segments = explode('/', $entry['path']);

                if ($entry['type'] === 'blob' && count($segments) - 1 <= $maxDepth && in_array(end($segments), $names, true)
                    && ! $this->isWithinExcluded($segments, $isExcluded)) {
                    $files[$entry['path']] = $entry['size'];
                }
            }

            return $files;
        }

        $files = [];
        $pending = [['path' => '', 'sha' => $treeSha, 'depth' => 0]];

        while (($level = array_shift($pending)) !== null) {
            $tree = $this->tree($repository, $level['sha'], false, $budget);

            if ($tree['truncated']) {
                throw new IncompleteListingException("[{$directory}/{$level['path']}] has too many entries to list.");
            }

            foreach ($tree['entries'] as $entry) {
                $path = ltrim("{$level['path']}/{$entry['path']}", '/');

                if ($isExcluded($path)) {
                    continue;
                }

                if ($entry['type'] === 'tree' && $level['depth'] < $maxDepth) {
                    $pending[] = ['path' => $path, 'sha' => $entry['sha'], 'depth' => $level['depth'] + 1];
                } elseif ($entry['type'] === 'blob' && in_array($entry['path'], $names, true)) {
                    $files[$path] = $entry['size'];
                }
            }
        }

        return $files;
    }

    public function read(string $repository, string $path, string $commitSha): string
    {
        return $this->get($this->contentsPath($repository, $path), ['ref' => $commitSha], 'application/vnd.github.raw+json')->body();
    }

    public function commitUrl(string $repository, string $commitSha): ?string
    {
        $webUrl = GitHubRepository::fromFullName($repository)?->webUrl();

        return $webUrl === null ? null : "{$webUrl}/commit/{$commitSha}";
    }

    /**
     * Client errors are not retried. 404 (missing, or not shared with the App), 409 (empty repository) and 422 (unknown
     * branch) mean the service settings are wrong.
     *
     * @param  array<string, mixed>  $query
     *
     * @throws RepositoryNotFoundException
     * @throws Throwable
     */
    private function get(string $path, array $query = [], string $accept = 'application/vnd.github+json'): Response
    {
        try {
            return CatalogHttp::client(config('psm.github.http_timeout_seconds'))
                ->retry(2, 500, fn (Throwable $exception): bool => ! ($exception instanceof RequestException && $exception->response->clientError()))
                ->withToken($this->authenticator->token())
                ->accept($accept)
                ->withHeaders(['X-GitHub-Api-Version' => self::API_VERSION])
                ->get(rtrim((string) config('psm.github.api_url'), '/')."/{$path}", $query);
        } catch (RequestException $exception) {
            if (in_array($exception->response->status(), [404, 409, 422], true)) {
                throw new RepositoryNotFoundException("GitHub answered {$exception->response->status()} for [{$path}].", previous: $exception);
            }

            throw $exception;
        }
    }

    /**
     * Whether the file at $segments, or one of the directories above it, is excluded.
     *
     * @param  list<string>  $segments
     * @param  Closure(string): bool  $isExcluded
     */
    private function isWithinExcluded(array $segments, Closure $isExcluded): bool
    {
        for ($length = 1; $length <= count($segments); $length++) {
            if ($isExcluded(implode('/', array_slice($segments, 0, $length)))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The tree of the directory at the commit, found segment by segment from the root tree of the commit. A commit
     * SHA names its root tree.
     *
     * @throws RepositoryNotFoundException When the directory does not exist at the commit.
     * @throws IncompleteListingException When a level is too large to tell.
     */
    private function treeSha(string $repository, string $directory, string $commitSha, int &$budget): string
    {
        $segments = array_values(array_filter(explode('/', $directory), fn (string $segment): bool => $segment !== ''));

        if (array_intersect($segments, ['.', '..']) !== []) {
            throw new InvalidArgumentException("[{$directory}] must not contain . or .. segments.");
        }

        $treeSha = $commitSha;

        foreach ($segments as $segment) {
            $tree = $this->tree($repository, $treeSha, false, $budget);
            $entry = collect($tree['entries'])->first(fn (array $entry): bool => $entry['path'] === $segment && $entry['type'] === 'tree');

            if ($entry === null) {
                throw $tree['truncated']
                    ? new IncompleteListingException("[{$directory}] could not be found in a tree GitHub cut short.")
                    : new RepositoryNotFoundException("[{$directory}] is not a directory.");
            }

            $treeSha = $entry['sha'];
        }

        return $treeSha;
    }

    /**
     * The entries of a tree, with paths relative to it. Links (mode 120000) and submodules are left out. Any value of
     * `recursive` makes GitHub recurse, so it is only sent when wanted.
     *
     * @return array{entries: list<array{path: string, type: 'blob'|'tree', sha: string, size: int}>, truncated: bool}
     *
     * @throws IncompleteListingException When the request budget is spent.
     */
    private function tree(string $repository, string $treeSha, bool $recursive, int &$budget): array
    {
        if ($budget-- <= 0) {
            throw new IncompleteListingException('The directory has too many subdirectories to list.');
        }

        $response = $this->get($this->repositoryPath($repository).'/git/trees/'.rawurlencode($treeSha), $recursive ? ['recursive' => 1] : [])->json();

        if (! is_array($response) || ! is_array($response['tree'] ?? null)) {
            throw new UnexpectedValueException('GitHub did not return the tree of the directory.');
        }

        $entries = [];

        foreach ($response['tree'] as $entry) {
            if (is_array($entry) && is_string($entry['path'] ?? null) && is_string($entry['sha'] ?? null) && in_array($entry['type'] ?? null, ['blob', 'tree'], true)
                && ($entry['mode'] ?? null) !== '120000') {
                $entries[] = ['path' => $entry['path'], 'type' => $entry['type'], 'sha' => $entry['sha'], 'size' => (int) ($entry['size'] ?? 0)];
            }
        }

        return ['entries' => $entries, 'truncated' => (bool) ($response['truncated'] ?? false)];
    }

    /**
     * E.g. "repos/acme/shop".
     */
    private function repositoryPath(string $repository): string
    {
        return (GitHubRepository::fromFullName($repository) ?? throw new InvalidArgumentException("[{$repository}] is not a repository full name."))->apiPath();
    }

    /**
     * E.g. "repos/acme/shop/contents/apps/api/composer.lock"; each segment is encoded.
     */
    private function contentsPath(string $repository, string $path): string
    {
        $segments = array_values(array_filter(explode('/', $path), fn (string $segment): bool => $segment !== ''));

        if (array_intersect($segments, ['.', '..']) !== []) {
            throw new InvalidArgumentException("[{$path}] must not contain . or .. segments.");
        }

        return implode('/', [$this->repositoryPath($repository), 'contents', ...array_map(rawurlencode(...), $segments)]);
    }
}
