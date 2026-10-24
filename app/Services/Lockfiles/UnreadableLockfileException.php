<?php

namespace App\Services\Lockfiles;

use UnexpectedValueException;

/**
 * The content is not a lockfile the parser understands. The message is technical, for logs and the admin's tooltip.
 */
class UnreadableLockfileException extends UnexpectedValueException {}
