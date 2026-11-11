<?php

namespace App\Services\ProjectImport;

use UnexpectedValueException;

/**
 * The upload is not a filled-in import template that can be read. The message is for the admin.
 */
class UnreadableImportException extends UnexpectedValueException {}
