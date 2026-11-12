<?php

namespace App\Services\Repositories;

use App\Services\Catalog\CatalogHttp;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\RequestException;
use Throwable;
use UnexpectedValueException;

/**
 * Asks GitHub who a personal access token signs in as (https://docs.github.com/en/rest/users/users#get-the-authenticated-user).
 * A classic token's scopes come in `X-OAuth-Scopes`, e.g. "repo, read:org"; a fine-grained token has none. A token
 * with an expiry date says it in `GitHub-Authentication-Token-Expiration`.
 */
class GitHubTokenInspector
{
    /**
     * @return array{login: string, scopes: string|null, expires_at: CarbonImmutable|null}
     *
     * @throws CredentialsRejectedException When GitHub turns the token down.
     * @throws Throwable When GitHub could not be reached.
     */
    public function inspect(string $token): array
    {
        try {
            $response = CatalogHttp::client(config('psm.github.http_timeout_seconds'))
                ->retry(2, 500, fn (Throwable $exception): bool => ! ($exception instanceof RequestException && $exception->response->clientError()))
                ->withToken($token)
                ->accept('application/vnd.github+json')
                ->withHeaders(['X-GitHub-Api-Version' => GitHubRepositoryReader::API_VERSION])
                ->get(rtrim((string) config('psm.github.api_url'), '/').'/user');
        } catch (RequestException $exception) {
            throw CredentialsRejectedException::fromResponse($exception->response) ?? $exception;
        }

        $login = $response->json('login');

        if (! is_string($login) || $login === '') {
            throw new UnexpectedValueException('GitHub did not return the account of the token.');
        }

        $scopes = trim($response->header('X-OAuth-Scopes'));
        $expiresAt = trim($response->header('GitHub-Authentication-Token-Expiration'));

        return [
            'login' => $login,
            'scopes' => $response->hasHeader('X-OAuth-Scopes') ? $scopes : null,
            'expires_at' => $expiresAt === '' ? null : CarbonImmutable::parse($expiresAt),
        ];
    }
}
