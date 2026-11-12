<?php

namespace App\Models;

use Database\Factories\AccessibleRepositoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A repository the GitHub App, or a repository connection, may read, e.g. "acme/shop"; one repository is listed once
 * per connection that reads it. The list only changes when an admin refreshes it, or an import file names a repository
 * it does not have (SyncAccessibleRepositories); a service keeps its repository when the repository later leaves the list.
 */
#[Fillable(['repository_connection_id', 'full_name', 'default_branch', 'is_private'])]
class AccessibleRepository extends Model
{
    /** @use HasFactory<AccessibleRepositoryFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_private' => 'boolean',
        ];
    }

    /**
     * The connection that lists it; null is the GitHub App.
     *
     * @return BelongsTo<RepositoryConnection, $this>
     */
    public function repositoryConnection(): BelongsTo
    {
        return $this->belongsTo(RepositoryConnection::class);
    }
}
