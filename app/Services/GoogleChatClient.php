<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class GoogleChatClient
{
    /**
     * Post a message to a Google Chat incoming webhook.
     *
     * Redirects are not followed and count as failures, so a validated webhook URL cannot bounce the request to another host.
     *
     * @param  array<string, mixed>  $message
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    public function send(string $webhookUrl, array $message): void
    {
        $response = Http::timeout(10)
            ->connectTimeout(5)
            ->withoutRedirecting()
            ->asJson()
            ->post($webhookUrl, $message);

        if (! $response->successful()) {
            throw new RequestException($response);
        }
    }
}
