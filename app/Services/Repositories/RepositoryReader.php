<?php

namespace App\Services\Repositories;

use App\Enums\LockfileSource;
use Closure;
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
     * The commit at the tip of $branch, or of the default branch when $branch is null. A tag or a commit SHA works too.
     *
     * @throws RepositoryNotFoundException When the repository or the branch does not exist or cannot be read.
     * @throws Throwable When the host could not be reached.
     */
    public function latestCommitSha(string $repository, ?string $branch): string;

    /**
     * The files named one of $names in $directory ("" is the root) or its subdirectories, at most $maxDepth levels
     * below it, at the commit, with their size in bytes. Links and submodules are left out. The listing is complete or
     * it throws: a file missing from it does not exist.
     *
     * @param  list<string>|Closure(string): bool  $names  E.g. "composer.lock", or whether a file name is wanted.
     * @param  Closure(string): bool  $isExcluded  Whether a path relative to $directory, of a directory or a file, is left out; an excluded directory is not entered.
     * @return array<string, int> By path relative to $directory, e.g. "frontend/package-lock.json".
     *
     * @throws RepositoryNotFoundException When the directory does not exist at the commit.
     * @throws IncompleteListingException When the directory is too large to list.
     * @throws Throwable When the host could not be reached.
     */
    public function findFiles(string $repository, string $directory, string $commitSha, array|Closure $names, int $maxDepth, Closure $isExcluded): array;

    /**
     * The tags of the repository with the commit each points at, e.g. ["v4" => "11bd71…", "v4.2.2" => "11bd71…"]. Public
     * repositories can be read even when the App is not installed on them.
     *
     * @return array<string, string>
     *
     * @throws RepositoryNotFoundException When the repository does not exist or cannot be read.
     * @throws Throwable When the host could not be reached.
     */
    public function tags(string $repository): array;

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
