<?php

namespace App\Models;

use Database\Factories\AccessibleRepositoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A repository the GitHub App may read, e.g. "acme/shop". The list only changes when an admin refreshes it
 * (SyncAccessibleRepositories); a service keeps its repository when the repository later leaves the list.
 */
#[Fillable(['full_name', 'default_branch', 'is_private'])]
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
}
