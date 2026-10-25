<?php

namespace App\Services\Repositories;

use App\Enums\LockfileSource;
use Throwable;

/**
 * Lists and reads git repositories with read-only credentials (handover D12). Repositories are named by their full
 * name, e.g. "acme/shop". GitHub is the only host today; a GitLab reader can implement the same interface later.
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
     * Every repository the credentials may read.
     *
     * @return list<array{full_name: string, default_branch: string|null, is_private: bool}>
     *
     * @throws Throwable When the host could not be reached.
     */
    public function repositories(): array;

    /**
     * Whether $branch exists in the repository. A repository that does not exist or cannot be read has no branches.
     *
     * @throws Throwable When the host could not be reached.
     */
    public function branchExists(string $repository, string $branch): bool;

    /**
     * The commit at the tip of $branch, or of the default branch when $branch is null.
     *
     * @throws RepositoryNotFoundException When the repository or the branch does not exist or cannot be read.
     * @throws Throwable When the host could not be reached.
     */
    public function latestCommitSha(string $repository, ?string $branch): string;

    /**
     * The files directly in $directory ("" is the root) at the commit, with their size in bytes.
     *
     * @return array<string, int> By file name; directories and links are left out.
     *
     * @throws RepositoryNotFoundException When the directory does not exist at the commit.
     * @throws Throwable When the host could not be reached.
     */
    public function filesIn(string $repository, string $directory, string $commitSha): array;

    /**
     * The content of the file at $path at the commit.
     *
     * @throws RepositoryNotFoundException When the file does not exist at the commit.
     * @throws Throwable When the host could not be reached.
     */
    public function read(string $repository, string $path, string $commitSha): string;

    /**
     * The page of a commit, e.g. https://github.com/acme/shop/commit/3f78…, or null for a name the host cannot read.
     */
    public function commitUrl(string $repository, string $commitSha): ?string;
}
