<?php

namespace App\Services\Notices;

use App\Enums\Technology;
use Carbon\CarbonInterface;
use Throwable;

/**
 * Reads what the keyword filter cannot from a vendor feed entry: whether it is security news at all, the vendor's
 * severity, when the fixes are planned, which branches they affect and which incident it may be about.
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
     * @param  list<array{id: int, title: string, published_at: ?CarbonInterface}>  $incidents  Recent incidents of the
     *                                                                                          technology the entry may
     *                                                                                          be about.
     *
     * @throws Throwable When the entry could not be classified.
     */
    public function classify(Technology $technology, NoticeEntry $entry, array $incidents = []): NoticeClassification;
}
