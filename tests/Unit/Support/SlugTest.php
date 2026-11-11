<?php

use App\Support\Slug;

it('makes the slug of a name as the forms suggest it', function (string $name, string $slug) {
    expect(Slug::from($name, 'project'))->toBe($slug);
})->with([
    ['Thanh toán Đà Nẵng', 'thanh-toan-da-nang'],
    ['  API  v2 ', 'api-v2'],
    ['決済システム', 'project'],
    ['---', 'project'],
]);

it('adds the first free number to a taken slug', function () {
    $taken = ['shop', 'shop-2'];

    expect(Slug::unique('shop', fn (string $slug): bool => in_array($slug, $taken, true)))->toBe('shop-3')
        ->and(Slug::unique('blog', fn (string $slug): bool => in_array($slug, $taken, true)))->toBe('blog');
});
