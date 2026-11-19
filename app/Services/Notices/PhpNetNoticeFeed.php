<?php

namespace App\Services\Notices;

use App\Services\Catalog\CatalogHttp;

/**
 * https://www.php.net/feed.atom is the php.net front page news (Atom): releases, conferences, and security
 * pre-announcements such as "Upcoming Critical PHP Security Release" (2026-10-20).
 */
class PhpNetNoticeFeed implements NoticeFeed
{
    public const string URL = 'https://www.php.net/feed.atom';

    /**
     * Match the notice polling interval. PHP.net's CDN can serve a valid but stale Atom feed at the fixed URL.
     */
    private const int CACHE_WINDOW_SECONDS = 15 * 60;

    public function entries(): array
    {
        $response = CatalogHttp::client()->get(self::URL, [
            'psm_poll' => intdiv(now()->getTimestamp(), self::CACHE_WINDOW_SECONDS),
        ]);

        return NewsFeedParser::parse($response->body());
    }
}
