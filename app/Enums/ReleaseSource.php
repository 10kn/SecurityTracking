<?php

namespace App\Enums;

use App\Services\Catalog\EndoflifeReleaseFeed;
use App\Services\Catalog\NodejsReleaseFeed;
use App\Services\Catalog\PhpNetReleaseFeed;
use App\Services\Catalog\ReleaseFeed;
use App\Services\Catalog\RubyLangReleaseFeed;
use App\Services\Notices\NodejsNoticeFeed;
use App\Services\Notices\NoticeFeed;
use App\Services\Notices\PhpNetNoticeFeed;
use App\Services\Notices\RubyLangNoticeFeed;

/**
 * Where the per-release list of a technology comes from. Branches and end-of-life dates always come from endoflife.date.
 */
enum ReleaseSource: string
{
    case Endoflife = 'endoflife';
    case PhpNet = 'php_net';
    case Nodejs = 'nodejs';
    case RubyLang = 'ruby_lang';

    public function getLabel(): string
    {
        return match ($this) {
            self::Endoflife => 'endoflife.date',
            self::PhpNet => 'php.net',
            self::Nodejs => 'nodejs.org',
            self::RubyLang => 'ruby-lang.org',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Endoflife => __('Bản mới nhất của mỗi nhánh. Dùng cho mọi công nghệ.'),
            self::PhpNet => __('Mọi bản PHP, có đánh dấu bản vá bảo mật. Chỉ dùng cho PHP.'),
            self::Nodejs => __('Mọi bản Node.js, có đánh dấu bản vá bảo mật. Chỉ dùng cho Node.js.'),
            self::RubyLang => __('Mọi bản Ruby. Chỉ dùng cho Ruby.'),
        };
    }

    /**
     * The page that announces a release, linked from Google Chat cards. Feeds without per-release pages link to the
     * technology's endoflife.date page.
     */
    public function releaseUrl(string $endoflifeProduct, string $version): string
    {
        return match ($this) {
            self::PhpNet => 'https://www.php.net/ChangeLog-'.explode('.', $version)[0].'.php#'.$version,
            self::Nodejs => 'https://nodejs.org/en/blog/release/v'.$version,
            self::RubyLang => 'https://www.ruby-lang.org/en/downloads/releases/',
            self::Endoflife => 'https://endoflife.date/'.rawurlencode($endoflifeProduct),
        };
    }

    /**
     * The vendor's security news feed that psm:watch-notices polls (handover 5.2), or null when the source has none.
     *
     * @return class-string<NoticeFeed>|null
     */
    public function noticeFeed(): ?string
    {
        return match ($this) {
            self::Endoflife => null,
            self::PhpNet => PhpNetNoticeFeed::class,
            self::Nodejs => NodejsNoticeFeed::class,
            self::RubyLang => RubyLangNoticeFeed::class,
        };
    }

    /**
     * Key of the news feed in source_heartbeats, and the `source` of the advisories it gives.
     */
    public function noticeSourceKey(): string
    {
        return "notice:{$this->value}";
    }

    /**
     * @return class-string<ReleaseFeed>
     */
    public function feed(): string
    {
        return match ($this) {
            self::Endoflife => EndoflifeReleaseFeed::class,
            self::PhpNet => PhpNetReleaseFeed::class,
            self::Nodejs => NodejsReleaseFeed::class,
            self::RubyLang => RubyLangReleaseFeed::class,
        };
    }
}
