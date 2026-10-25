<?php

namespace App\Services\Notices;

use App\Enums\Technology;
use Throwable;

/**
 * Reads what the keyword filter cannot from a vendor feed entry: whether it is security news at all, the vendor's
 * severity, when the fixes are planned and which branches they affect.
 */
interface NoticeClassifier
{
    /**
     * Whether the classifier is configured. SyncNoticeFeed keeps the keyword result alone when it is not.
     */
    public function isEnabled(): bool;

    /**
     * What classified an entry, stored on the advisory, e.g. "claude-haiku-5-5".
     */
    public function name(): string;

    /**
     * @throws Throwable When the entry could not be classified.
     */
    public function classify(Technology $technology, NoticeEntry $entry): NoticeClassification;
}
