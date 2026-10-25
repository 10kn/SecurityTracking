<?php

namespace App\Services\Repositories;

use RuntimeException;

/**
 * The repository, branch, directory or file does not exist, or the credentials are not allowed to read it. It is a
 * mistake in the project settings, not an outage of the host.
 */
class RepositoryNotFoundException extends RuntimeException {}
