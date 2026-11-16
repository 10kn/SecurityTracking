<?php

namespace App\Services\OsAdvisories;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use UnexpectedValueException;

/**
 * The security advisories an operating system publishes, e.g. Ubuntu's USN feed (see LinuxDistribution).
 */
interface OsAdvisoryFeed
{
    /**
     * Every advisory the feed lists now.
     *
     * @return list<OsAdvisoryEntry>
     *
     * @throws ConnectionException
     * @throws RequestException
     * @throws UnexpectedValueException When the response is not the feed.
     */
    public function entries(): array;
}
