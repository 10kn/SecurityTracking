<?php

namespace App\Services\Catalog;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

final class CatalogHttp
{
    /**
     * HTTP client for external data sources: bounded timeout (the catalog's unless given), two retries, and an error
     * on any non-2xx response.
     */
    public static function client(?int $timeoutSeconds = null): PendingRequest
    {
        return Http::timeout($timeoutSeconds ?? config('psm.catalog.http_timeout_seconds'))
            ->connectTimeout(10)
            ->withUserAgent(config('psm.catalog.user_agent'))
            ->retry(2, 500)
            ->throw();
    }
}
