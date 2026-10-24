<?php

namespace App\Services\Notices;

use App\Services\Catalog\CatalogHttp;

/**
 * https://www.ruby-lang.org/en/feeds/news.rss is the ruby-lang.org news (RSS): releases and security advisories
 * such as "Security advisories: CVE-…".
 */
class RubyLangNoticeFeed implements NoticeFeed
{
    public const string URL = 'https://www.ruby-lang.org/en/feeds/news.rss';

    public function entries(): array
    {
        return NewsFeedParser::parse(CatalogHttp::client()->get(self::URL)->body());
    }

    public function isSecurityOnly(): bool
    {
        return false;
    }
}
