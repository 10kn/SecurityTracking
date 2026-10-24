<?php

namespace App\Services\Catalog;

use App\Models\Technology;
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
}
