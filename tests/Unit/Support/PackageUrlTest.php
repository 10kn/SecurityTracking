<?php

use App\Enums\Ecosystem;
use App\Support\PackageUrl;

it('builds package URLs as the purl spec encodes them', function (Ecosystem $ecosystem, string $name, string $version, array $qualifiers, string $purl) {
    expect(PackageUrl::for($ecosystem, $name, $version, $qualifiers))->toBe($purl);
})->with([
    'composer' => [Ecosystem::Packagist, 'guzzlehttp/guzzle', '7.4.5', [], 'pkg:composer/guzzlehttp/guzzle@7.4.5'],
    'composer with a v prefix' => [Ecosystem::Packagist, 'symfony/process', 'v6.4.14', [], 'pkg:composer/symfony/process@v6.4.14'],
    'npm' => [Ecosystem::Npm, 'lodash', '4.17.21', [], 'pkg:npm/lodash@4.17.21'],
    'scoped npm package' => [Ecosystem::Npm, '@babel/traverse', '7.23.0', [], 'pkg:npm/%40babel/traverse@7.23.0'],
    'npm prerelease with build metadata' => [Ecosystem::Npm, 'demo', '1.0.0-beta.1+build.5', [], 'pkg:npm/demo@1.0.0-beta.1%2Bbuild.5'],
    'gem' => [Ecosystem::Rubygems, 'rack', '3.1.8', [], 'pkg:gem/rack@3.1.8'],
    'native gem' => [Ecosystem::Rubygems, 'nokogiri', '1.16.2', ['platform' => 'x86_64-linux'], 'pkg:gem/nokogiri@1.16.2?platform=x86_64-linux'],
]);
