<?php

namespace App\Services\GitHubActions;

use App\Models\GitHubActionVersion;
use App\Services\Repositories\RepositoryNotFoundException;
use App\Services\Repositories\RepositoryReader;
use App\Support\GitHubAction;
use Illuminate\Support\Collection;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Tells which release a reference in a workflow points at, since OSV can only compare release versions: `@v4.2.2` is
 * 4.2.2 as written; `@v4`, a commit SHA or a branch is the most precise release tag on the same commit, read from the
 * tags of the action's repository through the GitHub App (public repositories can be read without installing it).
 * It also reads the runtime the action declares (`runs.using` of its action.yml), for actions on an end-of-life
 * Node.js (see WorkflowFindings).
 *
 * Results are stored in github_action_versions and checked again after `psm.github_actions.recheck_minutes`, since
 * `@v4` moves with each release. A repository or reference GitHub does not know (a private action, a deleted branch)
 * resolves to no version and is not matched against advisories; an outage throws, so ScanPackages keeps the open
 * findings of GitHub Actions as they are.
 */
class ActionVersionResolver
{
    /**
     * @var array<string, array<string, string>> The tags of each repository read in this run.
     */
    private array $tags = [];

    public function __construct(private RepositoryReader $repositoryReader) {}

    /**
     * @param  iterable<array{0: string, 1: string}>  $references  [action, ref] pairs, e.g. ["actions/checkout", "v4"].
     * @return Collection<string, GitHubActionVersion> By "{action}@{ref}".
     *
     * @throws Throwable When GitHub could not be reached.
     */
    public function resolve(iterable $references): Collection
    {
        $stored = GitHubActionVersion::forReferences($references);
        $recheckBefore = now()->subMinutes((int) config('psm.github_actions.recheck_minutes'));
        $resolved = collect();

        foreach (collect($references)->unique(fn (array $reference): string => "{$reference[0]}@{$reference[1]}") as [$action, $ref]) {
            $key = "{$action}@{$ref}";
            $row = $stored->get($key);

            if ($row === null || $row->checked_at === null || $row->checked_at->lt($recheckBefore)) {
                $row = $this->check($row ?? new GitHubActionVersion(['action' => $action, 'ref' => $ref]));
            }

            $resolved->put($key, $row);
        }

        return $resolved;
    }

    /**
     * @throws Throwable
     */
    private function check(GitHubActionVersion $row): GitHubActionVersion
    {
        $commitSha = null;
        $version = GitHubAction::isExactVersion($row->ref) ? GitHubAction::version($row->ref) : null;

        if ($version === null && $this->repositoryReader->isConfigured()) {
            [$version, $commitSha] = $this->versionOnCommit(GitHubAction::repository($row->action), $row->ref);
        }

        $isSameCommit = $commitSha !== null && $commitSha === $row->commit_sha && $row->runs_using !== null;

        $row->fill([
            'version' => $version,
            'commit_sha' => $commitSha,
            'runs_using' => $isSameCommit ? $row->runs_using : $this->runsUsing($row->action, $commitSha ?? $row->ref),
            'checked_at' => now(),
        ])->save();

        return $row;
    }

    /**
     * The most precise release tag on the commit the reference points at, and that commit.
     *
     * @return array{0: string|null, 1: string|null}
     *
     * @throws Throwable
     */
    private function versionOnCommit(string $repository, string $ref): array
    {
        try {
            $tags = $this->tags[$repository] ??= $this->repositoryReader->tags($repository);
            $commitSha = match (true) {
                GitHubAction::isCommitSha($ref) => strtolower($ref),
                isset($tags[$ref]) => $tags[$ref],
                default => $this->repositoryReader->latestCommitSha($repository, $ref),
            };
        } catch (RepositoryNotFoundException) {
            return [null, null];
        }

        $version = collect($tags)
            ->filter(fn (string $tagCommit, string $tag): bool => $tagCommit === $commitSha && GitHubAction::version($tag) !== null)
            ->keys()
            ->map(fn (string $tag): string => (string) GitHubAction::version($tag))
            ->sort(fn (string $a, string $b): int => (substr_count($b, '.') <=> substr_count($a, '.')) ?: version_compare($b, $a))
            ->first();

        return [$version, $commitSha];
    }

    /**
     * `runs.using` of the action's action.yml (or action.yaml) at $ref, e.g. "node20", "composite" or "docker"; null
     * for a reusable workflow, which has none, or when it cannot be read.
     *
     * @throws Throwable
     */
    private function runsUsing(string $action, string $ref): ?string
    {
        if (GitHubAction::isReusableWorkflow($action) || ! $this->repositoryReader->isConfigured()) {
            return null;
        }

        $directory = GitHubAction::directory($action);

        foreach (['action.yml', 'action.yaml'] as $name) {
            try {
                $content = $this->repositoryReader->read(GitHubAction::repository($action), ltrim("{$directory}/{$name}", '/'), $ref);
            } catch (RepositoryNotFoundException) {
                continue;
            }

            try {
                $document = Yaml::parse($content);
            } catch (ParseException) {
                return null;
            }

            $using = is_array($document) && is_array($document['runs'] ?? null) ? ($document['runs']['using'] ?? null) : null;

            return is_string($using) ? strtolower($using) : null;
        }

        return null;
    }
}
