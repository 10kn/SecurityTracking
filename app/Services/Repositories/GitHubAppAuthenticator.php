<?php

namespace App\Services\Repositories;

use App\Services\Catalog\CatalogHttp;
use Carbon\CarbonImmutable;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use RuntimeException;
use UnexpectedValueException;

/**
 * Signs in as the GitHub App of `psm.github` (https://docs.github.com/en/apps/creating-github-apps/authenticating-with-a-github-app):
 * a JWT signed with the App's private key, valid for 9 minutes, is exchanged for an installation access token valid
 * for one hour, limited to reading contents and metadata. The token is kept in memory only, and a new one is asked
 * for shortly before it expires.
 */
class GitHubAppAuthenticator
{
    private ?string $token = null;

    private ?CarbonImmutable $expiresAt = null;

    public function isConfigured(): bool
    {
        return filled(config('psm.github.app_id'))
            && filled(config('psm.github.installation_id'))
            && filled(config('psm.github.private_key_path'));
    }

    /**
     * @throws RuntimeException When the App is not configured or its private key cannot be read.
     * @throws ConnectionException
     * @throws RequestException
     * @throws UnexpectedValueException When GitHub does not return a token.
     */
    public function token(): string
    {
        if ($this->token !== null && $this->expiresAt?->isAfter(now()->addMinutes(5))) {
            return $this->token;
        }

        if (! $this->isConfigured()) {
            throw new RuntimeException('The GitHub App is not configured (PSM_GITHUB_APP_ID, PSM_GITHUB_INSTALLATION_ID, PSM_GITHUB_PRIVATE_KEY_PATH).');
        }

        $installationId = rawurlencode((string) config('psm.github.installation_id'));

        $response = CatalogHttp::client(config('psm.github.http_timeout_seconds'))
            ->withToken($this->appJwt())
            ->accept('application/vnd.github+json')
            ->withHeaders(['X-GitHub-Api-Version' => GitHubRepositoryReader::API_VERSION])
            ->post(rtrim((string) config('psm.github.api_url'), '/')."/app/installations/{$installationId}/access_tokens", [
                'permissions' => ['contents' => 'read', 'metadata' => 'read'],
            ]);

        $token = $response->json('token');
        $expiresAt = $response->json('expires_at');

        if (! is_string($token) || $token === '' || ! is_string($expiresAt)) {
            throw new UnexpectedValueException('GitHub did not return an installation access token.');
        }

        $this->token = $token;
        $this->expiresAt = CarbonImmutable::parse($expiresAt);

        return $token;
    }

    /**
     * Issued a minute in the past to allow for clock drift, as GitHub recommends; GitHub rejects JWTs valid for more
     * than 10 minutes.
     */
    private function appJwt(): string
    {
        $privateKey = @file_get_contents((string) config('psm.github.private_key_path'));

        if ($privateKey === false || trim($privateKey) === '') {
            throw new RuntimeException('The private key of the GitHub App cannot be read at PSM_GITHUB_PRIVATE_KEY_PATH.');
        }

        $appId = (string) config('psm.github.app_id');

        return JWT::encode([
            'iat' => now()->subMinute()->getTimestamp(),
            'exp' => now()->addMinutes(9)->getTimestamp(),
            'iss' => ctype_digit($appId) ? (int) $appId : $appId,
        ], $privateKey, 'RS256');
    }
}
