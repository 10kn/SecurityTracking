<?php

namespace App\Services\Repositories;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * GitHub turned the credentials down: the token was revoked, expired or is wrong (401), or the organization does not
 * let it in (403), e.g. until it is authorized for SAML single sign-on or because it forbids classic tokens. Someone
 * has to act on GitHub; retrying does not help.
 */
class CredentialsRejectedException extends RuntimeException
{
    /**
     * The rejection a response stands for, or null for any other response. A 403 for a primary or secondary rate limit
     * is not one: it passes once the limit resets.
     */
    public static function fromResponse(Response $response): ?self
    {
        if ($response->status() === 401) {
            return new self(__('GitHub không nhận token: token đã bị thu hồi, hết hạn hoặc sai.'));
        }

        if ($response->status() !== 403 || $response->header('X-RateLimit-Remaining') === '0' || $response->header('Retry-After') !== '') {
            return null;
        }

        if ($response->header('X-GitHub-SSO') !== '') {
            return new self(__('Tổ chức trên GitHub bật SAML SSO: account cần Authorize SSO cho token với tổ chức này.'));
        }

        $message = $response->json('message');

        return new self(__('GitHub từ chối token: :message', ['message' => is_string($message) && $message !== '' ? $message : 'HTTP 403']));
    }
}
