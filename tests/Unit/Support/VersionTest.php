<?php

use App\Support\Version;

test('normalize extracts the upstream version', function (string $raw, ?string $expected) {
    expect(Version::normalize($raw))->toBe($expected);
})->with([
    'plain' => ['8.3.12', '8.3.12'],
    'leading v' => ['v20.11.0', '20.11.0'],
    'debian package' => ['8.3.12-1+deb.sury.org+1', '8.3.12'],
    'ubuntu package' => ['8.1.2-1ubuntu2.18', '8.1.2'],
    'ruby patchlevel' => ['2.7.8p225', '2.7.8'],
    'surrounding spaces' => ['  3.3.6 ', '3.3.6'],
    'branch only' => ['8.3', '8.3'],
    'not a version' => ['latest', null],
    'empty' => ['', null],
]);

test('branchOf picks the longest matching branch', function (string $version, array $branches, ?string $expected) {
    expect(Version::branchOf($version, $branches))->toBe($expected);
})->with([
    'php minor branch' => ['8.3.12', ['8.4', '8.3', '8.2'], '8.3'],
    'node major branch' => ['20.11.0', ['22', '20', '18'], '20'],
    'longest wins' => ['1.27.2', ['1', '1.27', '1.2'], '1.27'],
    'no partial number match' => ['8.30.1', ['8.3'], null],
    'unknown branch' => ['7.4.33', ['8.4', '8.3'], null],
]);

test('isReleaseOf rejects a version that only names the branch', function () {
    expect(Version::isReleaseOf('8.3.12', '8.3'))->toBeTrue()
        ->and(Version::isReleaseOf('8.3', '8.3'))->toBeFalse();
});

test('isNewer compares numerically', function () {
    expect(Version::isNewer('8.3.10', '8.3.9'))->toBeTrue()
        ->and(Version::isNewer('8.3.9', '8.3.10'))->toBeFalse()
        ->and(Version::isNewer('8.3.9', '8.3.9'))->toBeFalse();
});
