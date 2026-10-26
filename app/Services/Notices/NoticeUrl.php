<?php

namespace App\Services\Notices;

/**
 * Web addresses of feed entries, written so the same address compares equal: https (vendors serve the same page over
 * both), a lowercase host, no default port, "/" for an empty path. Path, query and fragment are kept as they are:
 * php.net tells its news apart by the fragment.
 */
final class NoticeUrl
{
    public static function normalize(?string $url): ?string
    {
        $parts = $url === null ? false : parse_url(trim($url));

        if ($parts === false || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || blank($parts['host'] ?? null)) {
            return null;
        }

        $port = isset($parts['port']) && ! in_array($parts['port'], [80, 443], true) ? ":{$parts['port']}" : '';
        $query = isset($parts['query']) ? "?{$parts['query']}" : '';
        $fragment = isset($parts['fragment']) ? "#{$parts['fragment']}" : '';

        return 'https://'.strtolower($parts['host']).$port.($parts['path'] ?? '/').$query.$fragment;
    }

    /**
     * The ways a normalized address may have been stored as given by a feed, lowercased: http or https, with or without
     * the default port, and without the "/" of an empty path. For narrowing a database search before normalize()
     * compares.
     *
     * @return list<string>
     */
    public static function storedForms(string $normalized): array
    {
        $parts = parse_url($normalized);

        if ($parts === false || ! isset($parts['host'])) {
            return [strtolower($normalized)];
        }

        $rest = strtolower(($parts['path'] ?? '/').(isset($parts['query']) ? "?{$parts['query']}" : '').(isset($parts['fragment']) ? "#{$parts['fragment']}" : ''));
        $rests = str_starts_with($rest, '/?') || str_starts_with($rest, '/#') || $rest === '/' ? [$rest, substr($rest, 1)] : [$rest];
        $host = strtolower($parts['host']).(isset($parts['port']) ? ":{$parts['port']}" : '');

        return collect(['http' => ['', ':80'], 'https' => ['', ':443']])
            ->flatMap(fn (array $ports, string $scheme): array => collect($ports)->flatMap(fn (string $port): array => array_map(fn (string $path): string => "{$scheme}://{$host}{$port}{$path}", $rests))->all())
            ->unique()
            ->values()
            ->all();
    }

    /**
     * A link of an entry as an absolute address: "//host/path" and "/path" are read against the entry's own address.
     * Other relative links are dropped, as feeds rarely give a usable base.
     */
    public static function resolve(string $href, ?string $base): ?string
    {
        $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $baseParts = $base === null ? false : parse_url($base);

        return match (true) {
            preg_match('#^https?://#i', $href) === 1 => self::normalize($href),
            str_starts_with($href, '//') => self::normalize("https:{$href}"),
            str_starts_with($href, '/') && is_array($baseParts) && isset($baseParts['host']) => self::normalize("https://{$baseParts['host']}{$href}"),
            default => null,
        };
    }
}
