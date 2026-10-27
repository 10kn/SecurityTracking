<?php

use App\Actions\SyncComponents;
use App\Enums\Ecosystem;
use App\Models\Deployment;
use App\Models\Lockfile;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    $this->deployment = Deployment::factory()->create();
});

it('reads each lockfile into components with their package URL and dev flag', function () {
    $lockfile = Lockfile::factory()->for($this->deployment)->withContent('composer.lock', lockfileFixture('composer.lock'))->create();
    Lockfile::factory()->for($this->deployment)->withContent('package-lock.json', lockfileFixture('package-lock.json'))->create();

    app(SyncComponents::class)->handle($this->deployment);

    expect($this->deployment->components()->count())->toBe(16)
        ->and($this->deployment->components()->where('name', 'phpunit/phpunit')->sole())
        ->ecosystem->toBe(Ecosystem::Packagist)
        ->version->toBe('10.5.0')
        ->purl->toBe('pkg:composer/phpunit/phpunit@10.5.0')
        ->lockfile_path->toBe('composer.lock')
        ->is_dev->toBeTrue()
        ->and($this->deployment->components()->where('name', 'semver')->pluck('version')->sort()->values()->all())->toBe(['5.7.1', '7.6.0'])
        ->and($lockfile->refresh())
        ->isParsed()->toBeTrue()
        ->package_count->toBe(5)
        ->parse_error->toBeNull();
});

it('keeps the rows of packages that stay when a lockfile changes', function () {
    $lockfile = Lockfile::factory()->for($this->deployment)->withContent('composer.lock', lockfileFixture('composer.lock'))->create();
    app(SyncComponents::class)->handle($this->deployment);
    $jwtId = $this->deployment->components()->where('name', 'firebase/php-jwt')->value('id');

    $upgraded = str_replace('"version": "3.0.0"', '"version": "3.0.2"', lockfileFixture('composer.lock'));
    $lockfile->update(['content' => $upgraded, 'content_hash' => hash('sha256', $upgraded)]);
    app(SyncComponents::class)->handle($this->deployment);

    expect($this->deployment->components()->where('name', 'firebase/php-jwt')->value('id'))->toBe($jwtId)
        ->and($this->deployment->components()->where('name', 'psr/log')->pluck('version')->all())->toBe(['3.0.2'])
        ->and($this->deployment->components()->count())->toBe(5);
});

it('does not read a lockfile again until its content changes', function () {
    Lockfile::factory()->for($this->deployment)->withContent('composer.lock', lockfileFixture('composer.lock'))->create();
    app(SyncComponents::class)->handle($this->deployment);
    $this->deployment->components()->where('name', 'psr/log')->delete();

    app(SyncComponents::class)->handle($this->deployment);

    expect($this->deployment->components()->count())->toBe(4);
});

it('removes the components of a removed lockfile', function () {
    $lockfile = Lockfile::factory()->for($this->deployment)->withContent('composer.lock', lockfileFixture('composer.lock'))->create();
    Lockfile::factory()->for($this->deployment)->withContent('Gemfile.lock', lockfileFixture('Gemfile.lock'))->create();
    app(SyncComponents::class)->handle($this->deployment);

    $lockfile->delete();
    app(SyncComponents::class)->handle($this->deployment);

    expect($this->deployment->components()->pluck('lockfile_path')->unique()->all())->toBe(['Gemfile.lock']);
});

it('keeps the components of the previous content when a lockfile cannot be read, and records why', function () {
    Log::spy();
    $lockfile = Lockfile::factory()->for($this->deployment)->withContent('composer.lock', lockfileFixture('composer.lock'))->create();
    app(SyncComponents::class)->handle($this->deployment);

    $lockfile->update(['content' => '{"packages": [', 'content_hash' => hash('sha256', '{"packages": [')]);
    app(SyncComponents::class)->handle($this->deployment);

    expect($this->deployment->components()->count())->toBe(5)
        ->and($lockfile->refresh())
        ->isUnreadable()->toBeTrue()
        ->parse_error->toStartWith('Not valid JSON')
        ->package_count->toBe(5);
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $context['path'] === 'composer.lock');
});

it('reads one npm lockfile per directory: pnpm-lock.yaml, else yarn.lock, else package-lock.json', function () {
    foreach (['package-lock.json' => 'package-lock.json', 'yarn.lock' => 'yarn.lock', 'pnpm-lock.yaml' => 'pnpm-lock.yaml', 'web/package-lock.json' => 'package-lock.json', 'web/yarn.lock' => 'yarn-v1.lock'] as $path => $fixture) {
        Lockfile::factory()->for($this->deployment)->withContent($path, lockfileFixture($fixture))->create();
    }

    app(SyncComponents::class)->handle($this->deployment);

    expect($this->deployment->components()->distinct()->orderBy('lockfile_path')->pluck('lockfile_path')->all())->toBe(['pnpm-lock.yaml', 'web/yarn.lock'])
        ->and($this->deployment->components()->where('lockfile_path', 'pnpm-lock.yaml')->where('name', 'left-pad')->sole()->is_dev)->toBeTrue()
        ->and($this->deployment->lockfiles()->where('path', 'package-lock.json')->sole()->isParsed())->toBeFalse();
});

it('reads a set-aside lockfile again when the lockfile read instead goes away', function () {
    Lockfile::factory()->for($this->deployment)->withContent('package-lock.json', lockfileFixture('package-lock.json'))->create();
    app(SyncComponents::class)->handle($this->deployment);
    $pnpmLock = Lockfile::factory()->for($this->deployment)->withContent('pnpm-lock.yaml', lockfileFixture('pnpm-lock.yaml'))->create();
    app(SyncComponents::class)->handle($this->deployment);

    expect($this->deployment->components()->where('lockfile_path', 'package-lock.json')->count())->toBe(0)
        ->and($this->deployment->lockfiles()->where('path', 'package-lock.json')->sole())
        ->parsed_hash->toBeNull()
        ->package_count->toBeNull();

    $pnpmLock->delete();
    app(SyncComponents::class)->handle($this->deployment);

    expect($this->deployment->components()->pluck('lockfile_path')->unique()->all())->toBe(['package-lock.json'])
        ->and($this->deployment->components()->count())->toBe(11);
});

it('keeps the components of the set-aside lockfile while the lockfile read instead cannot be read', function () {
    Log::spy();
    Lockfile::factory()->for($this->deployment)->withContent('package-lock.json', lockfileFixture('package-lock.json'))->create();
    app(SyncComponents::class)->handle($this->deployment);
    $pnpmLock = Lockfile::factory()->for($this->deployment)->withContent('pnpm-lock.yaml', "lockfileVersion: '10.0'\n")->create();

    app(SyncComponents::class)->handle($this->deployment);

    expect($this->deployment->components()->where('lockfile_path', 'package-lock.json')->count())->toBe(11)
        ->and($pnpmLock->refresh()->isUnreadable())->toBeTrue()
        ->and($this->deployment->lockfiles()->where('path', 'package-lock.json')->sole()->isParsed())->toBeTrue();

    $pnpmLock->update(['content' => lockfileFixture('pnpm-lock.yaml'), 'content_hash' => hash('sha256', lockfileFixture('pnpm-lock.yaml'))]);
    app(SyncComponents::class)->handle($this->deployment);

    expect($this->deployment->components()->pluck('lockfile_path')->unique()->all())->toBe(['pnpm-lock.yaml']);
});

it('never reads a set-aside lockfile, also while the lockfile read instead cannot be read', function () {
    Log::spy();
    $pnpmLock = Lockfile::factory()->for($this->deployment)->withContent('pnpm-lock.yaml', "lockfileVersion: '10.0'\n")->create();
    $packageLock = Lockfile::factory()->for($this->deployment)->withContent('package-lock.json', lockfileFixture('package-lock.json'))->create();
    $yarnLock = Lockfile::factory()->for($this->deployment)->withContent('yarn.lock', 'not a lockfile')->create();

    app(SyncComponents::class)->handle($this->deployment);

    expect($this->deployment->components()->count())->toBe(0)
        ->and($pnpmLock->refresh()->isUnreadable())->toBeTrue()
        ->and($packageLock->refresh()->isParsed())->toBeFalse()
        ->and($yarnLock->refresh()->isParsed())->toBeFalse();
});
