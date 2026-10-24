<?php

namespace App\Services\Catalog;

use App\Models\Technology;

/**
 * For technologies without an official feed: only the latest version of each branch is known, and not whether it
 * fixes vulnerabilities.
 */
class EndoflifeReleaseFeed implements ReleaseFeed
{
    public function releases(Technology $technology, array $branches): array
    {
        return array_values(array_map(
            fn (BranchData $branch): ReleaseData => new ReleaseData($branch->latestVersion),
            array_filter($branches, fn (BranchData $branch): bool => $branch->latestVersion !== null),
        ));
    }
}
