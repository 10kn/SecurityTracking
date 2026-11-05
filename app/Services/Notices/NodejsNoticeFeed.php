<?php

namespace App\Services\Notices;

use App\Services\Catalog\CatalogHttp;

/**
 * https://nodejs.org/en/feed/vulnerability.xml (RSS) lists only the Node.js vulnerability blog: security release
 * pre-announcements and assessments. Its items have a title and no text.
 */
class NodejsNoticeFeed implements NoticeFeed
{
    public const string URL = 'https://nodejs.org/en/feed/vulnerability.xml';

    public function entries(): array
    {
        return NewsFeedParser::parse(CatalogHttp::client()->get(self::URL)->body());
    }
}
