<?php

namespace App\Services\Repositories;

use App\Enums\LockfileSource;
use Throwable;

/**
 * Reads files of a git repository at a commit, with read-only credentials (handover D12). GitHub is the only host
 * today; a GitLab reader can implement the same interface later.
 */
interface RepositoryReader
{
    /**
     * The source heartbeat key and name of the host, e.g. "github" and "GitHub".
     */
    public function sourceKey(): string;

    public function sourceName(): string;

    /**
     * Where the lockfiles read through this reader come from.
     */
    public function lockfileSource(): LockfileSource;

    /**
     * Whether the credentials are set in the environment.
     */
    public function isConfigured(): bool;

    /**
     * Whether the repository URL points at this host, e.g. https://github.com/org/repo.
     */
    public function supports(string $repositoryUrl): bool;

    /**
     * The commit at the tip of $branch, or of the default branch when $branch is null.
     *
     * @throws RepositoryNotFoundException When the repository or the branch does not exist or cannot be read.
     * @throws Throwable When the host could not be reached.
     */
    public function latestCommitSha(string $repositoryUrl, ?string $branch): string;

    /**
     * The files directly in $directory ("" is the root) at the commit, with their size in bytes.
     *
     * @return array<string, int> By file name; directories and links are left out.
     *
     * @throws RepositoryNotFoundException When the directory does not exist at the commit.
     * @throws Throwable When the host could not be reached.
     */
    public function filesIn(string $repositoryUrl, string $directory, string $commitSha): array;

    /**
     * The content of the file at $path at the commit.
     *
     * @throws RepositoryNotFoundException When the file does not exist at the commit.
     * @throws Throwable When the host could not be reached.
     */
    public function read(string $repositoryUrl, string $path, string $commitSha): string;
}
