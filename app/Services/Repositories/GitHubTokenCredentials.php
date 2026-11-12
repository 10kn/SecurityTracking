<?php

namespace App\Services\Repositories;

use App\Models\RepositoryConnection;

/**
 * Signs in with the personal access token of a repository connection. GitHub lists the repositories the account owns,
 * collaborates on or reads as an organization member.
 */
class GitHubTokenCredentials implements GitHubCredentials
{
    public function __construct(private RepositoryConnection $connection) {}

    public function isConfigured(): bool
    {
        return filled($this->connection->token);
    }

    public function token(): string
    {
        return (string) $this->connection->token;
    }

    public function sourceKey(): string
    {
        return $this->connection->sourceKey();
    }

    public function sourceName(): string
    {
        return $this->connection->sourceName();
    }

    public function repositoryListing(): array
    {
        return ['path' => 'user/repos', 'query' => ['affiliation' => 'owner,collaborator,organization_member'], 'key' => null];
    }
}
