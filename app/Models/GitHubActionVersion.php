<?php

namespace App\Models;

use App\Support\GitHubAction;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * What a reference in a workflow pointed at when last checked (see ResolveGitHubActionVersions), e.g.
 * `actions/checkout@v4` → version "4.2.2", its commit and `runs.using` "node24". `version` is null when no release tag
 * is on the commit (a branch ahead of its last release, an action the App cannot read): such a reference is never
 * matched against advisories, and never counted as safe either.
 */
#[Table('github_action_versions')]
#[Fillable(['action', 'ref', 'version', 'commit_sha', 'runs_using', 'checked_at'])]
class GitHubActionVersion extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'checked_at' => 'datetime',
        ];
    }

    /**
     * The Node.js major version the action runs on, e.g. 20 for "node20"; null for Docker, composite and unknown ones.
     */
    public function nodeMajor(): ?int
    {
        return preg_match('/\Anode(\d+)\z/', (string) $this->runs_using, $matches) === 1 ? (int) $matches[1] : null;
    }

    /**
     * E.g. "actions/checkout@v4 (4.2.2)", or "@3f78685 (4.2.2)" for a commit; without the version when none is known.
     */
    public static function describe(string $action, string $ref, ?string $version): string
    {
        $shownRef = GitHubAction::isCommitSha($ref) ? substr($ref, 0, 7) : $ref;

        return "{$action}@{$shownRef}".($version === null || GitHubAction::version($ref) === $version ? '' : " ({$version})");
    }

    /**
     * The rows of several references at once, by "{action}@{ref}".
     *
     * @param  iterable<array{0: string, 1: string}>  $references  [action, ref] pairs.
     * @return Collection<string, self>
     */
    public static function forReferences(iterable $references): Collection
    {
        $pairs = collect($references)->unique(fn (array $reference): string => "{$reference[0]}@{$reference[1]}")->values();

        if ($pairs->isEmpty()) {
            return collect();
        }

        return $pairs->chunk(200)
            ->flatMap(fn (Collection $chunk) => self::where(function ($query) use ($chunk): void {
                foreach ($chunk as [$action, $ref]) {
                    $query->orWhere(fn ($pair) => $pair->where('action', $action)->where('ref', $ref));
                }
            })->get())
            ->keyBy(fn (self $row): string => "{$row->action}@{$row->ref}");
    }
}
