<?php

namespace App\Actions;

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
 */
class SyncComponents
{
    public function handle(Deployment $deployment): void
    {
        $lockfiles = $deployment->lockfiles()->get();

        $deployment->components()->whereNotIn('lockfile_path', $lockfiles->pluck('path'))->delete();

        foreach ($lockfiles as $lockfile) {
            if (! $lockfile->isParsed()) {
                $this->parse($deployment, $lockfile);
            }
        }
    }

    private function parse(Deployment $deployment, Lockfile $lockfile): void
    {
        try {
            $components = $lockfile->ecosystem->lockfileParser()->parse($lockfile->content);
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
}
