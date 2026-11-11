<?php

use App\Services\Repositories\GitHubRepository;

it('reads the repository from what people paste', function (string $url) {
    $repository = GitHubRepository::fromUrl($url);

    expect($repository)->not->toBeNull()
        ->and("{$repository->owner}/{$repository->name}")->toBe('acme/shop');
})->with([
    'https://github.com/acme/shop',
    'https://github.com/acme/shop.git',
    'https://github.com/acme/shop/',
    'https://github.com/acme/shop/tree/release/2026-10/apps/api',
    'https://www.github.com/acme/shop?tab=readme',
    'http://GitHub.com/acme/shop',
    'git@github.com:acme/shop.git',
    'github.com/acme/shop',
    'acme/shop',
    ' acme/shop ',
]);

it('reads nothing that is not a repository of the configured host', function (string $url) {
    expect(GitHubRepository::fromUrl($url))->toBeNull();
})->with([
    'https://gitlab.com/acme/shop',
    'https://github.com/acme',
    'acme',
    'acme/shop/extra',
    'https://github.com/../shop',
    'ftp://github.com.evil.example/acme/shop',
]);

it('reads repositories of a github enterprise host', function () {
    config(['psm.github.host' => 'git.example.com']);

    expect(GitHubRepository::fromUrl('https://git.example.com/acme/shop')?->name)->toBe('shop')
        ->and(GitHubRepository::fromUrl('https://github.com/acme/shop'))->toBeNull();
});
