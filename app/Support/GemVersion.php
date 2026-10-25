<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * RubyGems versions and requirements, compared as Gem::Version and Gem::Requirement do, which is how bundler-audit
 * tells whether a gem is patched: "6.1.7.rc1" is a prerelease below "6.1.7", and "~> 5.2.8" means ">= 5.2.8, < 5.3".
 */
final class GemVersion
{
    private const string VERSION = '[0-9]+(?>\.[0-9a-zA-Z]+)*(?:-[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?';

    /**
     * Whether Gem::Version can read the version: "1.16.2" and "1.0.0-beta.2" but not "dev-main".
     */
    public static function isValid(string $version): bool
    {
        return preg_match('/^\s*('.self::VERSION.')?\s*$/', $version) === 1;
    }

    /**
     * Whether the version meets every constraint of the requirement, e.g. "~> 6.1.7, >= 6.1.7.1".
     *
     * @throws InvalidArgumentException When the version or a constraint cannot be read.
     */
    public static function satisfies(string $version, string $requirement): bool
    {
        $segments = self::segments($version);

        foreach (self::constraints($requirement) as [$operator, $bound]) {
            $comparison = self::compareSegments($segments, $bound);

            $isMet = match ($operator) {
                '=' => $comparison === 0,
                '!=' => $comparison !== 0,
                '>' => $comparison > 0,
                '<' => $comparison < 0,
                '>=' => $comparison >= 0,
                '<=' => $comparison <= 0,
                '~>' => $comparison >= 0 && self::compareSegments(self::release($segments), self::bump($bound)) < 0,
            };

            if (! $isMet) {
                return false;
            }
        }

        return true;
    }

    /**
     * The lowest version the requirement names as allowed (=, >= or ~>), e.g. "6.1.7.1" for "~> 6.1.7, >= 6.1.7.1".
     * Null when no constraint names one, as in "> 1.0" or "< 2.0".
     *
     * @throws InvalidArgumentException When a constraint cannot be read.
     */
    public static function lowest(string $requirement): ?string
    {
        $lowest = null;

        foreach (self::constraints($requirement) as [$operator, $bound, $text]) {
            if (in_array($operator, ['=', '>=', '~>'], true) && ($lowest === null || self::compareSegments($bound, $lowest[0]) > 0)) {
                $lowest = [$bound, $text];
            }
        }

        return $lowest[1] ?? null;
    }

    /**
     * -1, 0 or 1 as the first version is lower than, equal to or higher than the second.
     *
     * @throws InvalidArgumentException When a version cannot be read.
     */
    public static function compare(string $version, string $other): int
    {
        return self::compareSegments(self::segments($version), self::segments($other));
    }

    /**
     * The constraints of a requirement as [operator, version segments, version], "=" when it names no operator.
     *
     * @return list<array{0: '='|'!='|'>'|'<'|'>='|'<='|'~>', 1: list<int|string>, 2: string}>
     */
    private static function constraints(string $requirement): array
    {
        return array_map(function (string $constraint): array {
            if (preg_match('/^\s*(~>|>=|<=|!=|=|>|<)?\s*('.self::VERSION.')\s*$/', $constraint, $matches) !== 1) {
                throw new InvalidArgumentException("Not a gem requirement: {$constraint}");
            }

            return [$matches[1] === '' ? '=' : $matches[1], self::segments($matches[2]), $matches[2]];
        }, explode(',', $requirement));
    }

    /**
     * The numbers and letters of a version: "1.0.0.rc1" and "1.0.0-rc1" are both [1, 0, 0, "rc", 1].
     *
     * @return list<int|string>
     */
    private static function segments(string $version): array
    {
        if (! self::isValid($version)) {
            throw new InvalidArgumentException("Not a gem version: {$version}");
        }

        $version = trim($version);
        preg_match_all('/[0-9]+|[a-z]+/i', $version === '' ? '0' : str_replace('-', '.pre.', $version), $matches);

        return array_map(fn (string $segment): int|string => ctype_digit($segment) ? (int) $segment : $segment, $matches[0]);
    }

    /**
     * Gem::Version#<=>: trailing zeros of the release and of the prerelease part do not count, missing segments are
     * zero, and letters sort below numbers, so a prerelease comes before its release.
     *
     * @param  list<int|string>  $segments
     * @param  list<int|string>  $other
     */
    private static function compareSegments(array $segments, array $other): int
    {
        $segments = self::canonical($segments);
        $other = self::canonical($other);

        for ($index = 0; $index < max(count($segments), count($other)); $index++) {
            $left = $segments[$index] ?? 0;
            $right = $other[$index] ?? 0;

            if ($left === $right) {
                continue;
            }

            if (is_string($left) !== is_string($right)) {
                return is_string($left) ? -1 : 1;
            }

            return is_string($left) ? strcmp($left, $right) <=> 0 : $left <=> $right;
        }

        return 0;
    }

    /**
     * @param  list<int|string>  $segments
     * @return list<int|string>
     */
    private static function canonical(array $segments): array
    {
        $release = self::release($segments);
        $prerelease = array_slice($segments, count($release));
        $withoutTrailingZeros = function (array $part): array {
            while ($part !== [] && end($part) === 0) {
                array_pop($part);
            }

            return $part;
        };

        return [...$withoutTrailingZeros($release), ...$withoutTrailingZeros($prerelease)];
    }

    /**
     * The segments before the first letter: "6.1.7" for "6.1.7.rc1".
     *
     * @param  list<int|string>  $segments
     * @return list<int>
     */
    private static function release(array $segments): array
    {
        $release = [];

        foreach ($segments as $segment) {
            if (is_string($segment)) {
                break;
            }

            $release[] = $segment;
        }

        return $release;
    }

    /**
     * The upper bound of "~>": the release without its last segment, the new last one raised by one. "5.3" for "5.2.8",
     * "6" for "5".
     *
     * @param  list<int|string>  $segments
     * @return list<int>
     */
    private static function bump(array $segments): array
    {
        $bumped = self::release($segments);

        if (count($bumped) > 1) {
            array_pop($bumped);
        }

        $bumped[count($bumped) - 1]++;

        return $bumped;
    }
}
