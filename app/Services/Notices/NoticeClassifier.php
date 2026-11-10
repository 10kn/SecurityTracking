<?php

namespace App\Services\Notices;

use App\Enums\Technology;
use Carbon\CarbonInterface;
use Throwable;

/**
 * Reads a vendor feed entry, which has no fixed structure: whether it is security news at all, the vendor's severity,
 * when the fixes are planned, which branches and operating systems they affect, the versions that fix them, whether they are exploited and
 * which incident it may be about. Nothing else reads the entry's words (see SyncNoticeFeed).
 */
interface NoticeClassifier
{
    /**
     * Whether the classifier is configured. Without it, no entry counts as security news unless its CVSS score or a
     * KEV listing says so.
     */
    public function isEnabled(): bool;

    /**
     * What classified an entry, stored on the advisory, e.g. "claude-haiku-5-5".
     */
    public function name(): string;

    /**
     * The revision of what the classifier reads from an entry, stored on the advisory: an entry read by an earlier one
     * is read again (see SyncNoticeFeed).
     */
    public function revision(): int;

    /**
     * @param  list<array{id: int, title: string, published_at: ?CarbonInterface}>  $incidents  Recent incidents of the
     *                                                                                          technology the entry may
     *                                                                                          be about.
     *
     * @throws Throwable When the entry could not be classified.
     */
    public function classify(Technology $technology, NoticeEntry $entry, array $incidents = []): NoticeClassification;
}
