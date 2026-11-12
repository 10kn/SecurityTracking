<?php

namespace App\Services\Repositories;

use App\Models\RepositoryConnection;
use App\Models\Service;

/**
 * The reader of each way to reach repositories: the GitHub App of `psm.github` (the container's RepositoryReader),
 * or a repository connection's token.
 */
class RepositoryReaders
{
    public function __construct(private RepositoryReader $gitHubApp) {}

    public function gitHubApp(): RepositoryReader
    {
        return $this->gitHubApp;
    }

    /**
     * The reader of $connection; null is the GitHub App.
     */
    public function forConnection(?RepositoryConnection $connection): RepositoryReader
    {
        return $connection === null ? $this->gitHubApp : new GitHubRepositoryReader(new GitHubTokenCredentials($connection));
    }

    public function forService(Service $service): RepositoryReader
    {
        return $this->forConnection($service->repositoryConnection);
    }
}
