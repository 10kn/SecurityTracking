<?php

namespace App\Services\Repositories;

use App\Enums\LockfileSource;
use App\Services\Catalog\CatalogHttp;
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

    public function filesIn(string $repository, string $directory, string $commitSha): array
    {
        $entries = $this->get($this->contentsPath($repository, $directory), ['ref' => $commitSha])->json();

        // The contents API answers with the file itself when the path is a file.
        if (! is_array($entries) || ! array_is_list($entries)) {
            throw new RepositoryNotFoundException("[{$directory}] is not a directory.");
        }

        $files = [];

        foreach ($entries as $entry) {
            if (is_array($entry) && ($entry['type'] ?? null) === 'file' && is_string($entry['name'] ?? null)) {
                $files[$entry['name']] = (int) ($entry['size'] ?? 0);
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
