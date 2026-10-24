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
