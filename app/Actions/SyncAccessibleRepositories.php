<?php

namespace App\Actions;

use App\Models\AccessibleRepository;
use App\Models\RepositoryConnection;
use App\Services\Repositories\RepositoryReaders;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Replaces the list of repositories a service can pick with the ones the GitHub App, or a repository connection, may
 * read now. Runs when an admin asks for it from the service form or a connection's page, when a connection is saved,
 * and when an import file names a repository the list does not have yet.
 */
class SyncAccessibleRepositories
{
    public function __construct(private RepositoryReaders $repositoryReaders) {}

    /**
     * Refreshes the repositories of one source: the GitHub App (null) or $connection. The other sources' are kept.
     *
     * @return int How many repositories the source may read.
     *
     * @throws RuntimeException When the App is not configured.
     * @throws Throwable When the host could not be reached; the list is then left as it was.
     */
    public function handle(?RepositoryConnection $connection = null): int
    {
        $repositoryReader = $this->repositoryReaders->forConnection($connection);

        if (! $repositoryReader->isConfigured()) {
            throw new RuntimeException(__('Chưa cấu hình GitHub App (PSM_GITHUB_APP_ID, PSM_GITHUB_INSTALLATION_ID, PSM_GITHUB_PRIVATE_KEY_PATH).'));
        }

        $repositories = $repositoryReader->repositories();
        $connectionId = $connection?->id;

        DB::transaction(function () use ($repositories, $connectionId): void {
            AccessibleRepository::where('repository_connection_id', $connectionId)
                ->whereNotIn('full_name', array_column($repositories, 'full_name'))
                ->delete();

            // Touched even when unchanged, so the list shows when it was refreshed.
            foreach ($repositories as $repository) {
                AccessibleRepository::updateOrCreate(['repository_connection_id' => $connectionId, 'full_name' => $repository['full_name']], $repository)->touch();
            }
        });

        return count($repositories);
    }

    /**
     * Refreshes every source: the GitHub App when it is configured, and each connection. A source that fails keeps
     * its list and does not stop the others.
     *
     * @return array{count: int, problems: list<string>} How many repositories the sources that answered may read, and one line per source that failed.
     *
     * @throws RuntimeException When there is no source: the App is not configured and there is no connection.
     */
    public function handleAll(): array
    {
        $connections = RepositoryConnection::orderBy('name')->get();
        $sources = [...($this->repositoryReaders->gitHubApp()->isConfigured() ? [null] : []), ...$connections->all()];

        if ($sources === []) {
            throw new RuntimeException(__('Chưa cấu hình GitHub App (PSM_GITHUB_APP_ID, PSM_GITHUB_INSTALLATION_ID, PSM_GITHUB_PRIVATE_KEY_PATH).'));
        }

        $count = 0;
        $problems = [];

        foreach ($sources as $connection) {
            try {
                $count += $this->handle($connection);
            } catch (Throwable $exception) {
                report($exception);
                $problems[] = $this->repositoryReaders->forConnection($connection)->sourceName().': '.$exception->getMessage();
            }
        }

        return ['count' => $count, 'problems' => $problems];
    }
}
