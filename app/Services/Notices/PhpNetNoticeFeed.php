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

    public function entries(): array
    {
        return NewsFeedParser::parse(CatalogHttp::client()->get(self::URL)->body());
    }

    public function isSecurityOnly(): bool
    {
        return false;
    }
}
