<?php

namespace App\Services\Notices;

use App\Support\Version;

final class VendorVersionScope
{
    /**
     * Fix thresholds apply within the branch that received the fix. A higher version on another branch may still be affected.
     *
     * @param  array{ranges: list<array{from: ?string, through: ?string}>, fixes: list<string>}  $scope
     */
    public static function affects(array $scope, string $version): bool
    {
        foreach ($scope['fixes'] as $fix) {
            if (Version::isReleaseOf($version, self::branch($fix)) && version_compare($version, $fix, '>=')) {
                return false;
            }
        }

        foreach ($scope['ranges'] as $range) {
            if (($range['from'] === null || version_compare($version, $range['from'], '>='))
                && ($range['through'] === null || version_compare($version, $range['through'], '<='))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{ranges: list<array{from: ?string, through: ?string}>, fixes: list<string>}  $scope
     * @param  list<string>  $releases
     * @return list<string>
     */
    public static function affectedBranches(array $scope, array $releases): array
    {
        $bounds = array_filter(array_merge(...array_map(array_values(...), $scope['ranges'])));

        return collect([...$bounds, ...$releases])
            ->filter(fn (string $release): bool => self::affects($scope, $release))
            ->map(self::branch(...))
            ->unique()
            ->sort(SORT_NATURAL)
            ->values()
            ->all();
    }

    private static function branch(string $version): string
    {
        return implode('.', array_slice(explode('.', $version), 0, 2));
    }
}
