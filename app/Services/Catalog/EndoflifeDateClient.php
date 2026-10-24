<?php

namespace App\Services\Catalog;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

/**
 * Reads https://endoflife.date (API v1): release branches, their support dates and latest version, for about 400 products.
 */
class EndoflifeDateClient
{
    public const string BASE_URL = 'https://endoflife.date/api/v1/products/';

    /**
     * The product's display name, e.g. "Node.js" for "nodejs", or null when endoflife.date does not know the product.
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    public function productLabel(string $product): ?string
    {
        try {
            return CatalogHttp::client()->get(self::BASE_URL.rawurlencode($product).'/')->json('result.label');
        } catch (RequestException $exception) {
            if ($exception->response->notFound()) {
                return null;
            }

            throw $exception;
        }
    }

    /**
     * @return list<BranchData>
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    public function branches(string $product): array
    {
        /** @var list<array<string, mixed>> $releases */
        $releases = CatalogHttp::client()->get(self::BASE_URL.rawurlencode($product).'/')->json('result.releases', []);

        return array_map(fn (array $release): BranchData => new BranchData(
            branch: (string) $release['name'],
            latestVersion: isset($release['latest']['name']) ? (string) $release['latest']['name'] : null,
            releasedAt: self::date($release['releaseDate'] ?? null),
            activeSupportUntil: self::date($release['eoasFrom'] ?? null),
            securitySupportUntil: self::date($release['eolFrom'] ?? null),
            isEol: (bool) ($release['isEol'] ?? false),
        ), $releases);
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : null;
    }
}
