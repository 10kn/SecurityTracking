<?php

namespace App\Services\Catalog;

use App\Enums\Technology;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

interface ReleaseFeed
{
    /**
     * Every published version of the technology that the source lists.
     *
     * @param  list<BranchData>  $branches  The technology's branches from endoflife.date.
     * @return list<ReleaseData>
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    public function releases(Technology $technology, array $branches): array;

    /**
     * Whether the source says which releases fix vulnerabilities. One that does not leaves `isSecurityRelease` null on
     * every release, and the version a pre-notice names is then taken for the fix as long as the catalog lists it (see
     * Incident::securityReleases()). A source that does keeps a missing flag from confirming any fix.
     */
    public static function flagsSecurityReleases(): bool;
}
