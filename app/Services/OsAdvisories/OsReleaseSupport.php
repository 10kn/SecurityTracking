<?php

namespace App\Services\OsAdvisories;

use App\Enums\LinuxDistribution;
use App\Services\Catalog\BranchData;
use App\Services\Catalog\EndoflifeDateClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;

/**
 * The releases of each operating system and the end of their standard support, from endoflife.date, kept from the
 * last successful read: a release past it gets no free fix any more (Ubuntu Pro and Debian LTS are not followed).
 */
class OsReleaseSupport
{
    private const string CACHE_PREFIX = 'psm:os-releases:';

    public function __construct(private EndoflifeDateClient $endoflife) {}

    /**
     * @throws ConnectionException
     * @throws RequestException
     */
    public function refresh(LinuxDistribution $os): void
    {
        $releases = array_map(fn (BranchData $branch): array => [
            'name' => $branch->branch,
            'label' => $branch->label ?? $branch->branch,
            'is_eol' => $branch->isEol,
            'eol_from' => $branch->securitySupportUntil?->toDateString(),
        ], $this->endoflife->branches($os->endoflifeProduct()));

        Cache::forever(self::CACHE_PREFIX.$os->endoflifeProduct(), $releases);
    }

    /**
     * @return list<array{name: string, label: string, is_eol: bool, eol_from: string|null}> Newest first; none until
     *                                                                                       the first successful read.
     */
    public function releases(LinuxDistribution $os): array
    {
        return Cache::get(self::CACHE_PREFIX.$os->endoflifeProduct(), []);
    }

    /**
     * The release by its version, e.g. "24.04", or its codename, e.g. "trixie" for Debian 13 ("13 (Trixie)").
     *
     * @return array{name: string, label: string, is_eol: bool, eol_from: string|null}|null
     */
    public function find(LinuxDistribution $os, string $release): ?array
    {
        $releases = collect($this->releases($os));

        return $releases->firstWhere('name', $release)
            ?? $releases->first(fn (array $known): bool => ! is_numeric($release) && preg_match('/\b'.preg_quote($release, '/').'\b/i', $known['label']) === 1);
    }

    /**
     * Whether the release is still in standard support. Before endoflife.date was ever read every release counts, so
     * nothing is missed.
     */
    public function isSupported(LinuxDistribution $os, string $release): bool
    {
        if ($this->releases($os) === []) {
            return true;
        }

        $known = $this->find($os, $release);

        return $known !== null && ! $known['is_eol'];
    }

    /**
     * The release's version as endoflife.date names it, e.g. "13" for "trixie"; the name given when it is unknown.
     */
    public function versionOf(LinuxDistribution $os, string $release): string
    {
        return $this->find($os, $release)['name'] ?? $release;
    }
}
