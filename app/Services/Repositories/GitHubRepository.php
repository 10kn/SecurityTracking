<?php

namespace App\Services\Repositories;

/**
 * A repository on the GitHub host of `psm.github.host`, e.g. github.com/acme/shop.
 */
final readonly class GitHubRepository
{
    public function __construct(public string $owner, public string $name) {}

    /**
     * Reads https://github.com/acme/shop(.git) or git@github.com:acme/shop(.git). Other hosts, and URLs pointing
     * inside a repository (e.g. /tree/main), give null.
     */
    public static function fromUrl(string $repositoryUrl): ?self
    {
        $host = preg_quote((string) config('psm.github.host'), '#');

        // The repository name is matched lazily, so a trailing ".git" is not part of it.
        if (preg_match("#^(?:https://{$host}/|git@{$host}:)([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+?)(?:\\.git)?/?$#i", trim($repositoryUrl), $matches) !== 1) {
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
