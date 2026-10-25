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
 * Reads repositories through the GitHub REST API (https://docs.github.com/en/rest), signed in as the GitHub App of
 * `psm.github`, which may only read contents and metadata.
 */
class GitHubRepositoryReader implements RepositoryReader
{
    public const string API_VERSION = '2022-11-28';

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

    public function supports(string $repositoryUrl): bool
    {
        return GitHubRepository::fromUrl($repositoryUrl) !== null;
    }

    public function latestCommitSha(string $repositoryUrl, ?string $branch): string
    {
        $commits = $this->get($repositoryUrl, 'commits', array_filter(['sha' => $branch, 'per_page' => 1]))->json();
        $sha = is_array($commits) ? ($commits[0]['sha'] ?? null) : null;

        if (! is_string($sha) || preg_match('/^[0-9a-f]{40}$/', $sha) !== 1) {
            throw new UnexpectedValueException('GitHub did not return the latest commit.');
        }

        return $sha;
    }

    public function filesIn(string $repositoryUrl, string $directory, string $commitSha): array
    {
        $entries = $this->get($repositoryUrl, $this->contentsPath($directory), ['ref' => $commitSha])->json();

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

    public function read(string $repositoryUrl, string $path, string $commitSha): string
    {
        return $this->get($repositoryUrl, $this->contentsPath($path), ['ref' => $commitSha], 'application/vnd.github.raw+json')->body();
    }

    /**
     * Client errors are not retried. 404 (missing, or not shared with the App), 409 (empty repository) and 422 (unknown
     * branch) mean the project settings are wrong.
     *
     * @param  array<string, mixed>  $query
     *
     * @throws RepositoryNotFoundException
     * @throws Throwable
     */
    private function get(string $repositoryUrl, string $path, array $query, string $accept = 'application/vnd.github+json'): Response
    {
        $repository = GitHubRepository::fromUrl($repositoryUrl)
            ?? throw new InvalidArgumentException("[{$repositoryUrl}] is not a GitHub repository.");

        try {
            return CatalogHttp::client(config('psm.github.http_timeout_seconds'))
                ->retry(2, 500, fn (Throwable $exception): bool => ! ($exception instanceof RequestException && $exception->response->clientError()))
                ->withToken($this->authenticator->token())
                ->accept($accept)
                ->withHeaders(['X-GitHub-Api-Version' => self::API_VERSION])
                ->get(rtrim((string) config('psm.github.api_url'), '/')."/{$repository->apiPath()}/{$path}", $query);
        } catch (RequestException $exception) {
            if (in_array($exception->response->status(), [404, 409, 422], true)) {
                throw new RepositoryNotFoundException("GitHub answered {$exception->response->status()} for [{$path}].", previous: $exception);
            }

            throw $exception;
        }
    }

    /**
     * E.g. "contents/apps/api/composer.lock"; each segment is encoded.
     */
    private function contentsPath(string $path): string
    {
        $segments = array_values(array_filter(explode('/', $path), fn (string $segment): bool => $segment !== ''));

        if (array_intersect($segments, ['.', '..']) !== []) {
            throw new InvalidArgumentException("[{$path}] must not contain . or .. segments.");
        }

        return implode('/', ['contents', ...array_map(rawurlencode(...), $segments)]);
    }
}
