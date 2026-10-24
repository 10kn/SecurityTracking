<?php

namespace App\Support;

use App\Enums\Ecosystem;

/**
 * Package URLs (https://github.com/package-url/purl-spec), the engine-neutral id components are stored under (D4):
 * `pkg:composer/guzzlehttp/guzzle@7.4.5`, `pkg:npm/%40babel/traverse@7.23.0`, `pkg:gem/nokogiri@1.16.2?platform=x86_64-linux`.
 */
final class PackageUrl
{
    /**
     * @param  array<string, string>  $qualifiers
     */
    public static function for(Ecosystem $ecosystem, string $name, string $version, array $qualifiers = []): string
    {
        $path = implode('/', array_map(self::encode(...), explode('/', $name)));

        ksort($qualifiers);

        $query = implode('&', array_map(
            fn (string $key, string $value): string => strtolower($key).'='.self::encode($value),
            array_keys($qualifiers),
            $qualifiers,
        ));

        return "pkg:{$ecosystem->purlType()}/{$path}@".self::encode($version).($query === '' ? '' : "?{$query}");
    }

    /**
     * Percent-encoding as the spec asks: `@` in a scope becomes `%40`; `:` stays as it is.
     */
    private static function encode(string $segment): string
    {
        return str_replace('%3A', ':', rawurlencode($segment));
    }
}
