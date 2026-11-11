<?php

namespace App\Support;

/**
 * An action or reusable workflow a workflow `uses`, named the way GitHub does: the repository, optionally a directory
 * of it, e.g. "actions/checkout", "github/codeql-action/init" or "acme/ci/.github/workflows/deploy.yml". Names are
 * kept in lower case, as GitHub matches them without case. Advisories name the repository only.
 */
final class GitHubAction
{
    /**
     * `owner/repo[/path]@ref`. Local actions (`./…`) and Docker images (`docker://…`) have no release to check.
     */
    private const string USES_PATTERN = '#\A([A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+(?:/[^@\s]+)?)@([^\s@]+)\z#';

    /**
     * A release tag such as "v4", "v4.2" or "4.2.2".
     */
    private const string VERSION_PATTERN = '/\Av?(\d+(?:\.\d+)*)\z/i';

    /**
     * The action and the reference of a `uses` value, e.g. "actions/checkout@v4" → ["actions/checkout", "v4"]. Null
     * for a local action, a Docker image or anything else.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function parseUses(string $uses): ?array
    {
        if (preg_match(self::USES_PATTERN, trim($uses), $matches) !== 1) {
            return null;
        }

        return [strtolower(rtrim($matches[1], '/')), $matches[2]];
    }

    /**
     * E.g. "github/codeql-action/init" → "github/codeql-action".
     */
    public static function repository(string $action): string
    {
        return implode('/', array_slice(explode('/', $action), 0, 2));
    }

    /**
     * The directory of the action in its repository, e.g. "init" for "github/codeql-action/init", "" at the root.
     */
    public static function directory(string $action): string
    {
        return implode('/', array_slice(explode('/', $action), 2));
    }

    /**
     * Whether it is a reusable workflow, which declares no runtime of its own.
     */
    public static function isReusableWorkflow(string $action): bool
    {
        return str_contains($action, '.github/workflows/');
    }

    /**
     * Whether $ref is a full commit SHA.
     */
    public static function isCommitSha(string $ref): bool
    {
        return preg_match('/\A[0-9a-f]{40}\z/i', $ref) === 1;
    }

    /**
     * The version of a release tag without its "v", e.g. "v4.2.2" → "4.2.2"; null for anything else, e.g. "main".
     */
    public static function version(string $tag): ?string
    {
        return preg_match(self::VERSION_PATTERN, $tag, $matches) === 1 ? $matches[1] : null;
    }

    /**
     * Whether $ref names one release, e.g. "v4.2.2", rather than a line of them that moves, e.g. "v4".
     */
    public static function isExactVersion(string $ref): bool
    {
        return substr_count((string) self::version($ref), '.') >= 2;
    }
}
