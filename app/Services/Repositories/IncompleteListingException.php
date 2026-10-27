<?php

namespace App\Services\Repositories;

use RuntimeException;

/**
 * A directory holds too many entries to list within the limits, so files missing from the listing may still exist.
 */
class IncompleteListingException extends RuntimeException {}
