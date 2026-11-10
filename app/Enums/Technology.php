<?php

namespace App\Enums;

use App\Models\RuntimeRelease;
use App\Models\SourceHeartbeat;
use App\Services\Catalog\NodejsReleaseFeed;
use App\Services\Catalog\PhpNetReleaseFeed;
use App\Services\Catalog\ReleaseFeed;
use App\Services\Catalog\RubyLangReleaseFeed;
use App\Services\Notices\NodejsNoticeFeed;
use App\Services\Notices\NoticeFeed;
use App\Services\Notices\PhpNetNoticeFeed;
use App\Services\Notices\RubyLangNoticeFeed;

/**
 * The runtimes whose versions are tracked. Supporting another one is a code change: its endoflife.date product for the
 * branches and end-of-life dates, its official release feed and its security news feed.
 */
enum Technology: string
{
    case Php = 'php';
    case Nodejs = 'nodejs';
    case Ruby = 'ruby';

    public function getLabel(): string
    {
        return match ($this) {
            self::Php => 'PHP',
            self::Nodejs => 'Node.js',
            self::Ruby => 'Ruby',
        };
    }

    /**
     * The product id on endoflife.date, where the branches and their support dates come from.
     */
    public function endoflifeProduct(): string
    {
        return $this->value;
    }

    public function endoflifeUrl(): string
    {
        return 'https://endoflife.date/'.rawurlencode($this->endoflifeProduct());
    }

    /**
     * The site that lists every release.
     */
    public function releaseSourceLabel(): string
    {
        return match ($this) {
            self::Php => 'php.net',
            self::Nodejs => 'nodejs.org',
            self::Ruby => 'ruby-lang.org',
        };
    }

    /**
     * The page of the site that lists every release, for people.
     */
    public function releaseSourceUrl(): string
    {
        return match ($this) {
            self::Php => 'https://www.php.net/releases/',
            self::Nodejs => 'https://nodejs.org/en/about/previous-releases',
            self::Ruby => 'https://www.ruby-lang.org/en/downloads/releases/',
        };
    }

    /**
     * The GitHub repository whose security advisories the vendor publishes, with a severity and the release of each
     * branch that fixes them (see VendorSecurityAdvisories). Null when the vendor publishes none there.
     */
    public function securityAdvisoryRepository(): ?string
    {
        return match ($this) {
            self::Php => 'php/php-src',
            self::Nodejs, self::Ruby => null,
        };
    }

    /**
     * @return class-string<ReleaseFeed>
     */
    public function releaseFeed(): string
    {
        return match ($this) {
            self::Php => PhpNetReleaseFeed::class,
            self::Nodejs => NodejsReleaseFeed::class,
            self::Ruby => RubyLangReleaseFeed::class,
        };
    }

    /**
     * The page that announces a release, linked from Google Chat cards.
     */
    public function releaseUrl(string $version): string
    {
        return match ($this) {
            self::Php => 'https://www.php.net/ChangeLog-'.explode('.', $version)[0].'.php#'.$version,
            self::Nodejs => 'https://nodejs.org/en/blog/release/v'.$version,
            self::Ruby => 'https://www.ruby-lang.org/en/downloads/releases/',
        };
    }

    /**
     * The vendor's security news feed that psm:watch-notices polls (handover 5.2). Every supported technology needs
     * one, so security releases announced ahead of time are not missed.
     *
     * @return class-string<NoticeFeed>
     */
    public function noticeFeed(): string
    {
        return match ($this) {
            self::Php => PhpNetNoticeFeed::class,
            self::Nodejs => NodejsNoticeFeed::class,
            self::Ruby => RubyLangNoticeFeed::class,
        };
    }

    /**
     * Key of the version catalog in source_heartbeats.
     */
    public function catalogSourceKey(): string
    {
        return "catalog:{$this->value}";
    }

    /**
     * Key of the security news feed in source_heartbeats, and the `source` of the advisories it gives.
     */
    public function noticeSourceKey(): string
    {
        return "notice:{$this->value}";
    }

    public function catalogHeartbeat(): ?SourceHeartbeat
    {
        return SourceHeartbeat::firstWhere('source', $this->catalogSourceKey());
    }

    /**
     * Versions offered while an admin types, newest first: every known release, end-of-life branches included because
     * legacy services still run them, optionally only those starting with the typed text, e.g. "8.3".
     *
     * @return list<string>
     */
    public function versionSuggestions(?string $startingWith = null): array
    {
        return RuntimeRelease::where('technology', $this)
            ->pluck('version')
            ->when(filled($startingWith), fn ($versions) => $versions->filter(fn (string $version): bool => str_starts_with($version, $startingWith)))
            ->sort(fn (string $a, string $b): int => version_compare($b, $a))
            ->values()
            ->all();
    }
}
