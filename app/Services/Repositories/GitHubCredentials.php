<?php

namespace App\Services\Repositories;

use Throwable;

/**
 * Who GitHubRepositoryReader signs in as: the GitHub App of `psm.github` (GitHubAppAuthenticator), or the personal
 * access token of a repository connection (GitHubTokenCredentials).
 */
interface GitHubCredentials
{
    /**
     * Whether the credentials are set.
     */
    public function isConfigured(): bool;

    /**
     * The bearer token of the next request.
     *
     * @throws Throwable When the token cannot be had.
     */
    public function token(): string;

    /**
     * The source heartbeat key and name of these credentials, e.g. "github" and "GitHub".
     */
    public function sourceKey(): string;

    public function sourceName(): string;

    /**
     * The API path listing every repository the credentials may read, its query, and the key of the list in the
     * response; a null key is a response that is the list itself.
     *
     * @return array{path: string, query: array<string, string>, key: string|null}
     */
    public function repositoryListing(): array;
}
