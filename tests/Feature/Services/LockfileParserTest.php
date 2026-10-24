<?php

use App\Enums\Ecosystem;
use App\Services\Lockfiles\ComponentData;
use App\Services\Lockfiles\ComposerLockParser;
use App\Services\Lockfiles\GemfileLockParser;
use App\Services\Lockfiles\NpmLockParser;
use App\Services\Lockfiles\UnreadableLockfileException;

/**
 * @param  list<ComponentData>  $components
 * @return array<string, bool> "name@version" => is_dev
 */
function packagesOf(array $components): array
{
    return collect($components)->mapWithKeys(fn (ComponentData $component): array => ["{$component->name}@{$component->version}" => $component->isDev])->sortKeys()->all();
}

it('reads composer.lock packages and marks packages-dev as dev dependencies', function () {
    $components = (new ComposerLockParser)->parse(lockfileFixture('composer.lock'));

    expect(packagesOf($components))->toBe([
        'firebase/php-jwt@v5.5.1' => false,
        'monolog/monolog@3.5.0' => false,
        'phpunit/phpunit@10.5.0' => true,
        'psr/log@3.0.0' => false,
        'symfony/process@v6.4.14' => false,
    ])
        ->and(collect($components)->pluck('ecosystem')->unique()->all())->toBe([Ecosystem::Packagist])
        ->and($components[0]->purl())->toBe('pkg:composer/firebase/php-jwt@v5.5.1');
});

it('reads the GEM specs of Gemfile.lock, moving the platform of native gems to the package URL', function () {
    $components = (new GemfileLockParser)->parse(lockfileFixture('Gemfile.lock'));

    expect(packagesOf($components))->toBe([
        'bigdecimal@3.1.8' => false,
        'google-protobuf@3.25.3' => false,
        'nio4r@2.7.3' => false,
        'puma@6.4.3' => false,
        'racc@1.8.1' => false,
        'rake@13.2.1' => false,
    ])
        ->and(collect($components)->firstWhere('name', 'google-protobuf')->purl())->toBe('pkg:gem/google-protobuf@3.25.3?platform=arm64-darwin')
        ->and(collect($components)->firstWhere('name', 'puma')->purl())->toBe('pkg:gem/puma@6.4.3');
});

it('leaves out gems from git and path sources', function () {
    $names = collect((new GemfileLockParser)->parse(lockfileFixture('Gemfile.lock')))->pluck('name');

    expect($names)->not->toContain('demo-internal-auth')->not->toContain('demo-shop');
});

it('reads Gemfile.lock files with Windows line endings', function () {
    $components = (new GemfileLockParser)->parse(str_replace("\n", "\r\n", lockfileFixture('Gemfile.lock')));

    expect($components)->toHaveCount(6);
});

it('reads every installed path of a package-lock.json v3, including several versions of one package', function () {
    $components = (new NpmLockParser)->parse(lockfileFixture('package-lock.json'));

    expect(packagesOf($components))->toBe([
        '@babel/helper-string-parser@7.24.8' => true,
        '@babel/traverse@7.23.0' => true,
        'lodash@4.17.21' => false,
        'minimist@0.0.10' => false,
        'minimist@1.2.0' => true,
        'ms@2.1.3' => true,
        'normalize-package-data@2.5.0' => false,
        'optimist@0.6.1' => false,
        'semver@5.7.1' => false,
        'semver@7.6.0' => false,
        'wordwrap@0.0.3' => false,
    ])
        ->and(collect($components)->firstWhere('name', '@babel/traverse')->purl())->toBe('pkg:npm/%40babel/traverse@7.23.0');
});

it('leaves out the root project, workspace folders, links and packages installed from git or a local path', function () {
    $lock = json_encode(['lockfileVersion' => 3, 'packages' => [
        '' => ['name' => 'demo', 'version' => '1.0.0'],
        'packages/demo-ui' => ['name' => 'demo-ui', 'version' => '0.1.0'],
        'node_modules/demo-ui' => ['resolved' => 'packages/demo-ui', 'link' => true],
        'node_modules/demo-fork' => ['version' => '2.0.0', 'resolved' => 'git+ssh://git@github.com/demo/demo-fork.git#0000000'],
        'node_modules/demo-local' => ['version' => '1.0.0', 'resolved' => 'file:../demo-local'],
        'node_modules/lodash-legacy' => ['name' => 'lodash', 'version' => '4.17.20', 'resolved' => 'https://registry.npmjs.org/lodash/-/lodash-4.17.20.tgz'],
    ]]);

    expect(packagesOf((new NpmLockParser)->parse((string) $lock)))->toBe(['lodash@4.17.20' => false]);
});

it('counts a package as a dev dependency only when every installed copy is', function () {
    $lock = json_encode(['lockfileVersion' => 3, 'packages' => [
        'node_modules/ms' => ['version' => '2.1.3', 'dev' => true],
        'node_modules/debug/node_modules/ms' => ['version' => '2.1.3'],
    ]]);

    expect(packagesOf((new NpmLockParser)->parse((string) $lock)))->toBe(['ms@2.1.3' => false]);
});

it('reads the nested dependencies of a package-lock.json v1', function () {
    $components = (new NpmLockParser)->parse(lockfileFixture('package-lock-v1.json'));

    expect(packagesOf($components))->toBe([
        'minimist@0.0.10' => false,
        'ms@2.1.3' => true,
        'optimist@0.6.1' => false,
        'wordwrap@0.0.3' => false,
    ]);
});

it('reads aliases and skips git dependencies of a package-lock.json v1', function () {
    $lock = json_encode(['lockfileVersion' => 1, 'dependencies' => [
        'lodash-legacy' => ['version' => 'npm:lodash@4.17.20'],
        'demo-fork' => ['version' => 'git+https://github.com/demo/demo-fork.git#0000000'],
    ]]);

    expect(packagesOf((new NpmLockParser)->parse((string) $lock)))->toBe(['lodash@4.17.20' => false]);
});

it('rejects content it cannot read', function (string $parser, string $content) {
    expect(fn () => app($parser)->parse($content))->toThrow(UnreadableLockfileException::class);
})->with([
    'composer.lock that is not JSON' => [ComposerLockParser::class, '{"packages": ['],
    'composer.lock without packages' => [ComposerLockParser::class, '{"name": "demo/app"}'],
    'composer.lock entry without a version' => [ComposerLockParser::class, '{"packages": [{"name": "psr/log"}]}'],
    'Gemfile.lock that is JSON' => [GemfileLockParser::class, '{"packages": []}'],
    'Gemfile.lock with a broken spec' => [GemfileLockParser::class, "GEM\n  remote: https://rubygems.org/\n  specs:\n    rack 3.1.8\n"],
    'package-lock.json that is not JSON' => [NpmLockParser::class, 'lockfileVersion: 3'],
    'package-lock.json of another tool' => [NpmLockParser::class, '{"name": "demo"}'],
    'package-lock.json v3 without packages' => [NpmLockParser::class, '{"lockfileVersion": 3}'],
]);
