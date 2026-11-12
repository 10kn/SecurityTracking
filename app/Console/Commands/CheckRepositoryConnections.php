<?php

namespace App\Console\Commands;

use App\Actions\CheckRepositoryConnection;
use App\Models\RepositoryConnection;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('psm:check-repository-connections')]
#[Description('Ask GitHub whether the token of each repository connection still works and when it expires')]
class CheckRepositoryConnections extends Command
{
    /**
     * Once a day, before the lockfiles are read, so a revoked or expiring token is told to the admin space.
     */
    public function handle(CheckRepositoryConnection $checkRepositoryConnection): int
    {
        $failed = false;

        foreach (RepositoryConnection::orderBy('id')->get() as $connection) {
            try {
                if (! $checkRepositoryConnection->handle($connection)) {
                    $this->components->warn("{$connection->name}: {$connection->last_error}");
                }
            } catch (Throwable $exception) {
                $failed = true;
                report($exception);
                $this->components->error("{$connection->name}: {$exception->getMessage()}");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
