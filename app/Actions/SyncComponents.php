<?php

namespace App\Actions;

use App\Enums\LockfileFormat;
use App\Models\Component;
use App\Models\Deployment;
use App\Models\Lockfile;
use App\Services\Lockfiles\ComponentData;
use App\Services\Lockfiles\UnreadableLockfileException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Makes a deployment's components match its lockfiles: a lockfile read for the first time or changed since it was
 * last read replaces its components, and the components of a removed lockfile go away. Rows of packages that stay
 * keep their id, so findings keep pointing at them.
 *
 * A lockfile that cannot be read keeps the components of its previous content: an unreadable upload is never
 * treated as "no packages". The error is logged and stored on the lockfile for the Packages column and the digest.
 *
 * Of several npm lockfiles in one directory only one is read (LockfileFormat::supersededPaths()). The others lose
 * their components once it could be read, and are read again if it goes away.
 */
class SyncComponents
{
    public function handle(Deployment $deployment): void
    {
        $lockfiles = $deployment->lockfiles()->get()->keyBy('path');
        $superseded = LockfileFormat::supersededPaths($lockfiles->keys());

        $deployment->components()->whereNotIn('lockfile_path', $lockfiles->keys())->delete();

        foreach ($lockfiles as $path => $lockfile) {
            if (! isset($superseded[$path]) && ! $lockfile->isParsed()) {
                $this->parse($deployment, $lockfile);
            }
        }

        foreach ($superseded as $path => $pathInUse) {
            if (! $lockfiles[$pathInUse]->isUnreadable()) {
                $this->setAside($deployment, $lockfiles[$path]);
            }
        }
    }

    private function parse(Deployment $deployment, Lockfile $lockfile): void
    {
        try {
            $components = LockfileFormat::forPath($lockfile->path)?->parser()->parse($lockfile->content)
                ?? throw new UnreadableLockfileException("Unsupported lockfile [{$lockfile->path}].");
        } catch (UnreadableLockfileException $exception) {
            Log::warning('Lockfile could not be read.', ['deployment_id' => $deployment->id, 'path' => $lockfile->path, 'error' => $exception->getMessage()]);

            $lockfile->update(['parsed_hash' => $lockfile->content_hash, 'parse_error' => Str::limit($exception->getMessage(), 490)]);

            return;
        }

        DB::transaction(function () use ($deployment, $lockfile, $components): void {
            $wanted = collect($components)->keyBy(fn (ComponentData $component): string => "{$component->name}@{$component->version}");

            $deployment->components()
                ->where('lockfile_path', $lockfile->path)
                ->get()
                ->reject(fn (Component $component): bool => $component->ecosystem === $lockfile->ecosystem && $wanted->has("{$component->name}@{$component->version}"))
                ->each(fn (Component $component) => $component->delete());

            foreach ($wanted->chunk(500) as $chunk) {
                Component::upsert($chunk->map(fn (ComponentData $component): array => [
                    'deployment_id' => $deployment->id,
                    'ecosystem' => $component->ecosystem->value,
                    'name' => $component->name,
                    'version' => $component->version,
                    'purl' => $component->purl(),
                    'lockfile_path' => $lockfile->path,
                    'is_dev' => $component->isDev,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])->values()->all(), ['deployment_id', 'lockfile_path', 'ecosystem', 'name', 'version'], ['purl', 'is_dev', 'updated_at']);
            }

            $lockfile->update(['parsed_hash' => $lockfile->content_hash, 'parse_error' => null, 'package_count' => $wanted->count()]);
        });
    }

    /**
     * Forgets what was read from a lockfile that another one in its directory replaces.
     */
    private function setAside(Deployment $deployment, Lockfile $lockfile): void
    {
        if ($lockfile->parsed_hash === null) {
            return;
        }

        DB::transaction(function () use ($deployment, $lockfile): void {
            $deployment->components()->where('lockfile_path', $lockfile->path)->delete();

            $lockfile->update(['parsed_hash' => null, 'parse_error' => null, 'package_count' => null]);
        });
    }
}
