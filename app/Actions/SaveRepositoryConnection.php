<?php

namespace App\Actions;

use App\Jobs\SyncServiceLockfilesJob;
use App\Models\RepositoryConnection;
use App\Models\Service;
use App\Services\Repositories\CredentialsRejectedException;
use App\Services\Repositories\GitHubTokenInspector;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Creates a repository connection, or gives one a new token. GitHub is asked about the token first, so a token it
 * turns down is never saved; the repositories the account may read are listed right after, and the services already
 * reading through the connection read their lockfiles again.
 */
class SaveRepositoryConnection
{
    public function __construct(private GitHubTokenInspector $tokenInspector, private SyncAccessibleRepositories $syncAccessibleRepositories) {}

    /**
     * @param  array{name?: string, token: string}  $attributes
     * @return array{connection: RepositoryConnection, repositories_count: int|null, problem: string|null} `problem` says why the repositories could not be listed.
     *
     * @throws ValidationException When GitHub turns the token down or cannot be reached.
     */
    public function handle(?RepositoryConnection $connection, array $attributes): array
    {
        try {
            $account = $this->tokenInspector->inspect($attributes['token']);
        } catch (CredentialsRejectedException $exception) {
            throw ValidationException::withMessages(['token' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages(['token' => __('Không kiểm tra được token trên GitHub, thử lại sau: :error', ['error' => $exception->getMessage()])]);
        }

        $connection ??= new RepositoryConnection;
        $connection->fill([
            ...$attributes,
            'account_login' => $account['login'],
            'scopes' => $account['scopes'],
            'expires_at' => $account['expires_at'],
            'last_checked_at' => now(),
            'last_error' => null,
        ])->save();

        $connection->services()->whereNotNull('repository')->each(fn (Service $service) => SyncServiceLockfilesJob::dispatch($service));

        try {
            $count = $this->syncAccessibleRepositories->handle($connection);
        } catch (Throwable $exception) {
            report($exception);

            return ['connection' => $connection, 'repositories_count' => null, 'problem' => $exception->getMessage()];
        }

        return ['connection' => $connection, 'repositories_count' => $count, 'problem' => null];
    }
}
