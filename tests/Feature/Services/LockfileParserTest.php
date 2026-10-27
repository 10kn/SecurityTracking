<?php

use App\Enums\Ecosystem;
use App\Services\Lockfiles\ComponentData;
use App\Services\Lockfiles\ComposerLockParser;
use App\Services\Lockfiles\GemfileLockParser;
use App\Services\Lockfiles\NpmLockParser;
use App\Services\Lockfiles\PnpmLockParser;
use App\Services\Lockfiles\UnreadableLockfileException;
use App\Services\Lockfiles\YarnLockParser;

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

/**
 * The registry packages of tests/Fixtures/lockfiles/pnpm-lock*.yaml and yarn*.lock, generated by pnpm 7.33, 8.15
 * and 12.9 and Yarn 1.22, 3.8 and 4.18 from one project: a workspace folder (demo-ui), an alias (lodash-legacy), a
 * git dependency (is-number 7.0.0), a local folder (demo-local), a tarball URL (is-odd), peers (react-dom) and a
 * patch (ms). minimist 1.2.0 is only a devDependency of the project, left-pad only of demo-ui; ms and wordwrap are
 * devDependencies too, but also installed for production.
 *
 * @return array<string, bool>
 */
function demoShopPackages(): array
{
    return [
        '@sindresorhus/is@4.6.0' => false,
        'es-errors@1.3.0' => false,
        'function-bind@1.1.2' => false,
        'hasown@2.0.4' => false,
        'hosted-git-info@2.8.9' => false,
        'is-core-module@2.17.0' => false,
        'is-number@6.0.0' => false,
        'is-odd@3.0.1' => false,
        'js-tokens@4.0.0' => false,
        'left-pad@1.3.0' => true,
        'lodash@4.17.20' => false,
        'lodash@4.18.1' => false,
        'loose-envify@1.4.0' => false,
        'minimist@0.0.10' => false,
        'minimist@1.2.0' => true,
        'ms@2.1.3' => false,
        'normalize-package-data@2.5.0' => false,
        'optimist@0.6.1' => false,
        'path-parse@1.0.7' => false,
        'react-dom@18.2.0' => false,
        'react@18.2.0' => false,
        'resolve@1.22.13' => false,
        'scheduler@0.23.2' => false,
        'semver@5.7.2' => false,
        'semver@7.8.5' => false,
        'spdx-correct@3.2.0' => false,
        'spdx-exceptions@2.5.0' => false,
        'spdx-expression-parse@3.0.1' => false,
        'spdx-license-ids@3.0.24' => false,
        'supports-preserve-symlinks-flag@1.0.0' => false,
        'validate-npm-package-license@3.0.4' => false,
        'wordwrap@0.0.3' => false,
    ];
}

it('reads the registry packages of a pnpm-lock.yaml and which are only installed for development', function (string $fixture) {
    $components = (new PnpmLockParser)->parse(lockfileFixture($fixture));

    expect(packagesOf($components))->toBe(demoShopPackages())
        ->and(collect($components)->firstWhere('name', '@sindresorhus/is')->purl())->toBe('pkg:npm/%40sindresorhus/is@4.6.0');
})->with([
    'lockfile 9.0 after the package manager\'s own lockfile (pnpm 12)' => 'pnpm-lock.yaml',
    'lockfile 6.0 (pnpm 8)' => 'pnpm-lock-v6.yaml',
    'lockfile 5.4 (pnpm 7)' => 'pnpm-lock-v5.yaml',
]);

it('walks pnpm 9 dependencies through workspace folders injected as files', function () {
    $lock = <<<'YAML'
        lockfileVersion: '9.0'

        importers:

          .:
            dependencies:
              demo-ui:
                specifier: workspace:*
                version: file:packages/demo-ui(react@18.2.0)
            devDependencies:
              react:
                specifier: 18.2.0
                version: 18.2.0

          packages/demo-ui:
            dependencies:
              ms:
                specifier: 2.1.3
                version: 2.1.3

        packages:

          demo-ui@file:packages/demo-ui:
            resolution: {directory: packages/demo-ui, type: directory}

          ms@2.1.3:
            resolution: {integrity: sha512-6FlzubTLZG3J2a/NVCAleEhjzq5oxgHyaCU9yYXvcLsvoVaHJq/s5xXI6/XXP6tz7R9xAOtHnSO/tXtF3WRTlA==}

          react@18.2.0:
            resolution: {integrity: sha512-/3IjMdb2L9QbBdWiW5e3P2/npwMBaU9mHCSCUzNln0ZCYbcfTsGbTJrU/kGemdH2IWmB2ioZ+zkxtmq6g09fGQ==}

        snapshots:

          demo-ui@file:packages/demo-ui(react@18.2.0):
            dependencies:
              ms: 2.1.3
              react: 18.2.0

          ms@2.1.3: {}

          react@18.2.0: {}
        YAML;

    expect(packagesOf((new PnpmLockParser)->parse($lock)))->toBe(['ms@2.1.3' => false, 'react@18.2.0' => false]);
});

it('follows pnpm 9 aliases whose package name starts with a digit', function () {
    $lock = <<<'YAML'
        lockfileVersion: '9.0'

        importers:

          .:
            dependencies:
              zip:
                specifier: npm:7zip-bin@5.2.0
                version: 7zip-bin@5.2.0
            devDependencies:
              7zip-bin:
                specifier: 5.2.0
                version: 5.2.0

        packages:

          7zip-bin@5.2.0:
            resolution: {integrity: sha512-ukTPVhqG4jNzMro2qA9HSCSSVJN3aN7tlb+hfqYCt3ER0yWroeA2VR38MNrOHLQ/cVj+DaIMad0kFCtWWowh/A==}

          ms@2.1.3:
            resolution: {integrity: sha512-6FlzubTLZG3J2a/NVCAleEhjzq5oxgHyaCU9yYXvcLsvoVaHJq/s5xXI6/XXP6tz7R9xAOtHnSO/tXtF3WRTlA==}

        snapshots:

          7zip-bin@5.2.0:
            dependencies:
              ms: 2.1.3

          ms@2.1.3: {}
        YAML;

    expect(packagesOf((new PnpmLockParser)->parse($lock)))->toBe(['7zip-bin@5.2.0' => false, 'ms@2.1.3' => false]);
});

it('reads a pnpm-lock.yaml with Windows line endings', function () {
    expect(packagesOf((new PnpmLockParser)->parse(str_replace("\n", "\r\n", lockfileFixture('pnpm-lock.yaml')))))->toBe(demoShopPackages());
});

it('reads pnpm keys whose local path holds parentheses and 5.x references to another registry', function () {
    $lock = str_replace('local/demo-local', 'local/(shared)/demo-local', lockfileFixture('pnpm-lock.yaml'));
    $legacy = "lockfileVersion: 5.4\nspecifiers:\n  left-pad: 1.3.0\ndependencies:\n  left-pad: 127.0.0.1+4873/left-pad/1.3.0\npackages:\n  127.0.0.1+4873/left-pad/1.3.0:\n    resolution: {integrity: sha512-XI5MPzVNApjAyhQzphX8BkmKsKUxD4LdyK24iZeQGinBN9yTQT3bFlCBy/aVx2HrNcqQGsdot8ghrjyrvMCoEA==, registry: http://127.0.0.1:4873/, tarball: http://127.0.0.1:4873/left-pad/-/left-pad-1.3.0.tgz}\n    dev: false\n";

    expect(packagesOf((new PnpmLockParser)->parse($lock)))->toBe(demoShopPackages())
        ->and((new PnpmLockParser)->parse($legacy))->toBe([]);
});

it('counts a pnpm 9 package as production when the walk does not reach it', function () {
    $lock = "lockfileVersion: '9.0'\nimporters:\n  .: {}\npackages:\n  ms@2.1.3: {}\nsnapshots:\n  ms@2.1.3: {}\n";

    expect(packagesOf((new PnpmLockParser)->parse($lock)))->toBe(['ms@2.1.3' => false]);
});

it('reads a pnpm-lock.yaml of a project without dependencies', function (string $lock) {
    expect((new PnpmLockParser)->parse($lock))->toBe([]);
})->with([
    'lockfile 9.0' => "lockfileVersion: '9.0'\n\nsettings:\n  autoInstallPeers: true\n  excludeLinksFromLockfile: false\n\nimporters:\n\n  .: {}\n",
    'lockfile 5.4' => "lockfileVersion: 5.4\n\nspecifiers: {}\n",
]);

it('reads the registry packages of a yarn.lock as production dependencies', function (string $fixture, array $leftOut) {
    $components = (new YarnLockParser)->parse(lockfileFixture($fixture));
    $expected = collect(demoShopPackages())->except($leftOut)->map(fn (): bool => false)->all();

    expect(packagesOf($components))->toBe($expected)
        ->and(collect($components)->firstWhere('name', '@sindresorhus/is')->purl())->toBe('pkg:npm/%40sindresorhus/is@4.6.0');
})->with([
    'Yarn 4 (version 10)' => ['yarn.lock', ['is-odd@3.0.1']],
    'Yarn 3 (version 6)' => ['yarn-v6.lock', ['is-odd@3.0.1']],
    'Yarn 1' => ['yarn-v1.lock', ['is-odd@3.0.1']],
]);

it('reads a yarn.lock without packages', function (string $lock) {
    expect((new YarnLockParser)->parse($lock))->toBe([]);
})->with([
    'Yarn 1' => "# THIS IS AN AUTOGENERATED FILE. DO NOT EDIT THIS FILE DIRECTLY.\n# yarn lockfile v1\n\n\n",
    'Yarn 4' => "# This file is generated by running \"yarn install\" inside your project.\n\n__metadata:\n  version: 10\n  cacheKey: 10c0\n\n\"demo@workspace:.\":\n  version: 0.0.0-use.local\n  resolution: \"demo@workspace:.\"\n  languageName: unknown\n  linkType: soft\n",
]);

it('keeps a Yarn 1 package when any of the ranges resolved to it is a registry range', function () {
    $lock = <<<'LOCK'
        # yarn lockfile v1


        "is-odd@https://registry.yarnpkg.com/is-odd/-/is-odd-3.0.1.tgz", is-odd@latest:
          version "3.0.1"
          resolved "https://registry.yarnpkg.com/is-odd/-/is-odd-3.0.1.tgz#65101baf3727d728b66fa62f50cda7f2d3989601"

        "demo-fork@github:demo/demo-fork#0000000":
          version "2.0.0"
          resolved "https://codeload.github.com/demo/demo-fork/tar.gz/0000000"
        LOCK;

    expect(packagesOf((new YarnLockParser)->parse($lock)))->toBe(['is-odd@3.0.1' => false]);
});

it('keeps a package Yarn patches only when the patched package comes from the registry', function () {
    $lock = <<<'YAML'
        __metadata:
          version: 8
          cacheKey: 10c0

        "resolve@patch:resolve@npm%3A^1.10.0#optional!builtin<compat/resolve>":
          version: 1.22.13
          resolution: "resolve@patch:resolve@npm%3A1.22.13#optional!builtin<compat/resolve>::version=1.22.13&hash=9bd1a5"
          languageName: node
          linkType: hard

        "demo-fork@patch:demo-fork@https%3A//github.com/demo/demo-fork.git%23commit=0000000#./demo-fork.patch::locator=demo%40workspace%3A.":
          version: 2.0.0
          resolution: "demo-fork@patch:demo-fork@https%3A//github.com/demo/demo-fork.git%23commit=0000000#./demo-fork.patch::version=2.0.0&hash=000000&locator=demo%40workspace%3A."
          languageName: node
          linkType: hard
        YAML;

    expect(packagesOf((new YarnLockParser)->parse($lock)))->toBe(['resolve@1.22.13' => false]);
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
    'pnpm-lock.yaml that is not YAML' => [PnpmLockParser::class, "lockfileVersion: '9.0'\nimporters: [\n"],
    'pnpm-lock.yaml without lockfileVersion' => [PnpmLockParser::class, "importers:\n  .: {}\n"],
    'pnpm-lock.yaml of lockfile 7.0' => [PnpmLockParser::class, "lockfileVersion: '7.0'\nimporters:\n  .: {}\n"],
    'pnpm-lock.yaml whose last document is not a lockfile' => [PnpmLockParser::class, "---\nlockfileVersion: '9.0'\nimporters:\n  .: {}\n---\nsettings: {}\n"],
    'pnpm-lock.yaml with packages that are not a map' => [PnpmLockParser::class, "lockfileVersion: '9.0'\npackages: ms@2.1.3\n"],
    'pnpm-lock.yaml 9.0 cut off before packages' => [PnpmLockParser::class, "lockfileVersion: '9.0'\nimporters:\n  .:\n    dependencies:\n      lodash:\n        specifier: 4.17.20\n        version: 4.17.20\n"],
    'pnpm-lock.yaml 6.0 cut off before packages' => [PnpmLockParser::class, "lockfileVersion: '6.0'\ndependencies:\n  lodash:\n    specifier: 4.17.20\n    version: 4.17.20\n"],
    'pnpm-lock.yaml 9.0 without the package of a snapshot' => [PnpmLockParser::class, "lockfileVersion: '9.0'\nimporters:\n  .:\n    dependencies:\n      lodash:\n        specifier: 4.17.20\n        version: 4.17.20\npackages:\n  ms@2.1.3: {}\nsnapshots:\n  lodash@4.17.20: {}\n  ms@2.1.3: {}\n"],
    'pnpm-lock.yaml 9.0 without the snapshot of a dependency' => [PnpmLockParser::class, "lockfileVersion: '9.0'\nimporters:\n  .:\n    dependencies:\n      lodash:\n        specifier: 4.17.20\n        version: 4.17.20\npackages:\n  ms@2.1.3: {}\nsnapshots:\n  ms@2.1.3: {}\n"],
    'yarn.lock that is JSON' => [YarnLockParser::class, '{"packages": []}'],
    'yarn.lock of Yarn 2' => [YarnLockParser::class, "__metadata:\n  version: 4\n"],
    'yarn.lock of Yarn 4 with an entry without resolution' => [YarnLockParser::class, "__metadata:\n  version: 10\n\n\"ms@npm:^2.1.3\":\n  version: 2.1.3\n"],
    'yarn.lock of Yarn 1 with an entry without version' => [YarnLockParser::class, "# yarn lockfile v1\n\nms@^2.1.3:\n  resolved \"https://registry.yarnpkg.com/ms/-/ms-2.1.3.tgz\"\n"],
    'yarn.lock of Yarn 1 with a broken line' => [YarnLockParser::class, "# yarn lockfile v1\n\nms@^2.1.3\n  version \"2.1.3\"\n"],
]);

it('rejects a pnpm-lock.yaml cut off part way', function (string $fixture, string $cutBefore) {
    $content = lockfileFixture($fixture);

    expect(fn () => (new PnpmLockParser)->parse(substr($content, 0, (int) strpos($content, $cutBefore))))
        ->toThrow(UnreadableLockfileException::class, 'is not in the lockfile');
})->with([
    'lockfile 9.0 in snapshots' => ['pnpm-lock.yaml', "\n  lodash@4.17.20: {}"],
    'lockfile 6.0' => ['pnpm-lock-v6.yaml', "\n  /lodash@4.17.20:"],
    'lockfile 5.4' => ['pnpm-lock-v5.yaml', "\n  /lodash/4.17.20:"],
]);

it('says which lockfile version it cannot read', function () {
    expect(fn () => (new PnpmLockParser)->parse("lockfileVersion: '10.0'\n"))->toThrow(UnreadableLockfileException::class, 'pnpm lockfile version 10.0 is not supported (5.x, 6.x and 9.x are).')
        ->and(fn () => (new YarnLockParser)->parse("__metadata:\n  version: 11\n"))->toThrow(UnreadableLockfileException::class, 'yarn.lock version 11 is not supported');
});
