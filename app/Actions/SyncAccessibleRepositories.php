<?php

namespace App\Actions;

use App\Models\AccessibleRepository;
use App\Services\Repositories\RepositoryReader;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Replaces the list of repositories a service can pick with the ones the GitHub App may read now. Runs only when an
 * admin asks for it, from the service form.
 */
class SyncAccessibleRepositories
{
    public function __construct(private RepositoryReader $repositoryReader) {}

    /**
     * @return int How many repositories the App may read.
     *
     * @throws RuntimeException When the App is not configured.
     * @throws Throwable When the host could not be reached; the list is then left as it was.
     */
    public function handle(): int
    {
        if (! $this->repositoryReader->isConfigured()) {
            throw new RuntimeException(__('Chưa cấu hình GitHub App (PSM_GITHUB_APP_ID, PSM_GITHUB_INSTALLATION_ID, PSM_GITHUB_PRIVATE_KEY_PATH).'));
        }

        $repositories = $this->repositoryReader->repositories();

        DB::transaction(function () use ($repositories): void {
            AccessibleRepository::whereNotIn('full_name', array_column($repositories, 'full_name'))->delete();

            // Touched even when unchanged, so the list shows when it was refreshed.
            foreach ($repositories as $repository) {
                AccessibleRepository::updateOrCreate(['full_name' => $repository['full_name']], $repository)->touch();
            }
        });

        return count($repositories);
    }
}
