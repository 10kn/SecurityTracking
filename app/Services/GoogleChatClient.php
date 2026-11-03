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
     * @param  array<string, string>  $query  Added to the webhook's query string, which keeps its key and token, e.g. `messageReplyOption`.
     * @return array<string, mixed> What Google Chat answered, e.g. `['name' => 'spaces/…/messages/…', 'thread' => ['name' => 'spaces/…/threads/…']]`.
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    public function send(string $webhookUrl, array $message, array $query = []): array
    {
        $url = $query === [] ? $webhookUrl : $webhookUrl.(str_contains($webhookUrl, '?') ? '&' : '?').http_build_query($query);

        $response = Http::timeout(10)
            ->connectTimeout(5)
            ->withoutRedirecting()
            ->asJson()
            ->post($url, $message);

        if (! $response->successful()) {
            throw new RequestException($response);
        }

        $answer = $response->json();

        return is_array($answer) ? $answer : [];
    }
}
