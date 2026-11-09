<?php

namespace App\Enums;

use App\Services\OsAdvisories\AmazonLinuxAlasFeed;
use App\Services\OsAdvisories\DebianDsaFeed;
use App\Services\OsAdvisories\OsAdvisoryFeed;
use App\Services\OsAdvisories\UbuntuUsnFeed;

/**
 * The Linux distributions of the free AWS images whose security advisories psm:watch-os-advisories follows, stored in
 * `advisories.operating_system`. Each has the distribution's own advisory feed: Amazon Linux one per major version
 * (ALAS), Ubuntu (USN) and Debian (DSA) one for every release, so only their releases still in standard support count
 * (see OsReleaseSupport). Not applied to projects yet: the advisories are listed for the admin and sent to the
 * security news space. Not the operating system family a deployment runs on (see OperatingSystem).
 */
enum LinuxDistribution: string
{
    case AmazonLinux2023 = 'amazon-linux-2023';
    case AmazonLinux2 = 'amazon-linux-2';
    case Ubuntu = 'ubuntu';
    case Debian = 'debian';

    public function getLabel(): string
    {
        return match ($this) {
            self::AmazonLinux2023 => 'Amazon Linux 2023',
            self::AmazonLinux2 => 'Amazon Linux 2',
            self::Ubuntu => 'Ubuntu',
            self::Debian => 'Debian',
        };
    }

    public function feed(): OsAdvisoryFeed
    {
        return match ($this) {
            self::AmazonLinux2023 => new AmazonLinuxAlasFeed(AmazonLinuxAlasFeed::AL2023_URL, '2023'),
            self::AmazonLinux2 => new AmazonLinuxAlasFeed(AmazonLinuxAlasFeed::AL2_URL, '2'),
            self::Ubuntu => app(UbuntuUsnFeed::class),
            self::Debian => app(DebianDsaFeed::class),
        };
    }

    /**
     * The product id on endoflife.date, where the releases and their end of standard support come from.
     */
    public function endoflifeProduct(): string
    {
        return match ($this) {
            self::AmazonLinux2023, self::AmazonLinux2 => 'amazon-linux',
            self::Ubuntu => 'ubuntu',
            self::Debian => 'debian',
        };
    }

    /**
     * Whether advisories are kept only for the releases still in standard support. An Amazon Linux feed covers the one
     * major version the admin chose to follow, so its advisories are kept even past its end of life, which the list shows.
     */
    public function keepsSupportedReleasesOnly(): bool
    {
        return in_array($this, [self::Ubuntu, self::Debian], true);
    }

    /**
     * Key of the feed in source_heartbeats, and the `source` of the advisories it gives.
     */
    public function sourceKey(): string
    {
        return "os:{$this->value}";
    }

    public static function fromSourceKey(string $source): ?self
    {
        return str_starts_with($source, 'os:') ? self::tryFrom(substr($source, 3)) : null;
    }
}
