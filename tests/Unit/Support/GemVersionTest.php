<?php

use App\Support\GemVersion;

it('checks a version against a requirement as Gem::Requirement does', function (string $version, string $requirement, bool $expected) {
    expect(GemVersion::satisfies($version, $requirement))->toBe($expected);
})->with([
    'at the lower bound' => ['6.4.3', '>= 6.4.3', true],
    'below the lower bound' => ['6.4.2', '>= 6.4.3', false],
    'every constraint met' => ['5.2.8.15', '~> 5.2.8, >= 5.2.8.15', true],
    'one constraint missed' => ['5.2.8.14', '~> 5.2.8, >= 5.2.8.15', false],
    'inside a pessimistic range' => ['5.2.99', '~> 5.2.8', true],
    'past a pessimistic range' => ['5.3.0', '~> 5.2.8', false],
    'a prerelease of the next branch' => ['5.3.0.rc1', '~> 5.2.8', false],
    'a pessimistic major version' => ['5.9', '~> 5', true],
    'past a pessimistic major version' => ['6.0', '~> 5', false],
    'a prerelease below its release' => ['7.0.0.rc1', '>= 7.0.0', false],
    'a prerelease under its release' => ['7.0.0.rc1', '< 7.0.0', true],
    'trailing zeros' => ['1.0', '= 1.0.0', true],
    'a dashed prerelease' => ['1.0.0-beta.2', '>= 1.0.0.beta.1', true],
    'not equal' => ['2.0', '!= 2.0.0', false],
    'no operator' => ['3.0', '3.0', true],
    'numbers, not text' => ['1.10', '> 1.9', true],
]);

it('names the lowest version a requirement allows', function (string $requirement, ?string $lowest) {
    expect(GemVersion::lowest($requirement))->toBe($lowest);
})->with([
    'the highest lower bound' => ['~> 6.1.7, >= 6.1.7.1', '6.1.7.1'],
    'a lower bound' => ['>= 7.0.4.1', '7.0.4.1'],
    'a pessimistic range' => ['~> 5.2.8', '5.2.8'],
    'only an upper bound' => ['< 3.0.0', null],
    'an exclusive lower bound' => ['> 1.0', null],
]);

it('compares versions', function () {
    expect(GemVersion::compare('1.0.0', '1'))->toBe(0)
        ->and(GemVersion::compare('1.10', '1.9'))->toBe(1)
        ->and(GemVersion::compare('1.0.a', '1.0'))->toBe(-1);
});

it('refuses versions and requirements RubyGems cannot read', function (string $version, string $requirement) {
    GemVersion::satisfies($version, $requirement);
})->with([
    'a branch' => ['dev-main', '>= 1.0'],
    'a requirement in words' => ['1.0', 'about 2.0'],
])->throws(InvalidArgumentException::class);

it('tells whether a version can be read', function () {
    expect(GemVersion::isValid('1.16.2'))->toBeTrue()
        ->and(GemVersion::isValid('1.0.0-beta.2'))->toBeTrue()
        ->and(GemVersion::isValid('dev-main'))->toBeFalse();
});
