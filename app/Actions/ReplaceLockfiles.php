<?php

namespace App\Actions;

use App\Enums\LockfileSource;
use App\Models\Deployment;
use App\Support\LockfilePath;
use InvalidArgumentException;

/**
 * Writes the lockfiles of a deployment, uploaded by an admin or read from the project's repository.
 */
class ReplaceLockfiles
{
    /**
     * A lockfile replaces the one at the same path (relative to the service directory, see LockfilePath). Identical
     * content keeps the row, so its packages are not read again; only where it came from is updated.
     *
     * @param  list<array{path: string, content: string}>  $lockfiles
     * @param  list<string>  $removedPaths
     * @return bool Whether a lockfile was added, changed or removed.
     */
    public function handle(Deployment $deployment, array $lockfiles, array $removedPaths = [], LockfileSource $source = LockfileSource::Upload, ?string $commitSha = null): bool
    {
        $hasChanges = $removedPaths !== []
            && $deployment->lockfiles()->whereIn('path', $removedPaths)->delete() > 0;

        foreach ($lockfiles as ['path' => $path, 'content' => $content]) {
            $ecosystem = (LockfilePath::isValid($path) ? LockfilePath::ecosystem($path) : null)
                ?? throw new InvalidArgumentException("Unsupported lockfile [{$path}].");

            $contentHash = hash('sha256', $content);
            $existing = $deployment->lockfiles()->where('path', $path)->first();

            if ($existing?->content_hash === $contentHash) {
                $existing->update(['source' => $source, 'commit_sha' => $commitSha]);

                continue;
            }

            $deployment->lockfiles()->updateOrCreate(['path' => $path], [
                'ecosystem' => $ecosystem,
                'source' => $source,
                'commit_sha' => $commitSha,
                'content' => $content,
                'content_hash' => $contentHash,
                'uploaded_at' => now(),
            ]);

            $hasChanges = true;
        }

        return $hasChanges;
    }
}
