<?php

namespace App\Actions;

use App\Jobs\SyncServiceLockfilesJob;
use App\Models\Project;
use App\Models\Service;
use App\Services\ProjectImport\ImportPlan;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Creates the projects and services of a checked import file (ImportPlanner) all at once, or nothing. Each service is
 * saved as the service form saves it: its production inventory through UpdateProductionInventory, so it is evaluated,
 * then its lockfiles read from GitHub when it has a repository.
 */
class ImportProjects
{
    public function __construct(private UpdateProductionInventory $updateProductionInventory) {}

    /**
     * @return array{projects: int, services: int} How many were created.
     */
    public function handle(ImportPlan $plan): array
    {
        if ($plan->hasErrors()) {
            throw new LogicException('An import file with errors cannot be imported.');
        }

        [$projects, $services] = DB::transaction(function () use ($plan): array {
            /** @var array<string, Project> $projects */
            $projects = [];
            $services = [];

            foreach ($plan->rowsToCreate() as $row) {
                $project = $projects[(string) $row->projectKey] ??= $row->projectId !== null
                    ? Project::findOrFail($row->projectId)
                    : Project::create(['team_id' => $row->teamId, 'name' => $row->projectName, 'slug' => $row->projectSlug]);

                $service = Service::create([
                    'project_id' => $project->id,
                    'name' => $row->serviceName,
                    'slug' => $row->serviceSlug,
                    'kind' => $row->kind,
                    'exposure' => $row->exposure,
                    'repository_path' => $row->repositoryPath,
                    'repository' => $row->repository,
                    'repository_branch' => $row->repository !== null ? $row->repositoryBranch : null,
                ]);

                $this->updateProductionInventory->handle($service, $row->runtimeVersions, os: $row->os);
                $services[] = $service;
            }

            return [$projects, $services];
        });

        foreach ($services as $service) {
            if ($service->syncsLockfiles()) {
                SyncServiceLockfilesJob::dispatch($service);
            }
        }

        return [
            'projects' => count(array_filter($projects, fn (Project $project): bool => $project->wasRecentlyCreated)),
            'services' => count($services),
        ];
    }
}
