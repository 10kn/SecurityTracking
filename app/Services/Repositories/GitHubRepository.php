<?php

namespace App\Services\Repositories;

/**
 * A repository on the GitHub host of `psm.github.host`, e.g. github.com/acme/shop.
 */
final readonly class GitHubRepository
{
    public function __construct(public string $owner, public string $name) {}

    /**
     * Reads a full name such as "acme/shop"; anything else, including "." and ".." segments, gives null.
     */
    public static function fromFullName(string $fullName): ?self
    {
        if (preg_match('#^([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+)$#', $fullName, $matches) !== 1) {
            return null;
        }

        if (in_array($matches[1], ['.', '..'], true) || in_array($matches[2], ['.', '..'], true)) {
            return null;
        }

        return new self($matches[1], $matches[2]);
    }

    /**
     * E.g. "https://github.com/acme/shop".
     */
    public function webUrl(): string
    {
        return 'https://'.config('psm.github.host')."/{$this->owner}/{$this->name}";
    }

    /**
     * The repository's part of an API path, e.g. "repos/acme/shop".
     */
    public function apiPath(): string
    {
        return 'repos/'.rawurlencode($this->owner).'/'.rawurlencode($this->name);
    }
}
