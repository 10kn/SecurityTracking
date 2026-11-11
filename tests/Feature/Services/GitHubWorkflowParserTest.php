<?php

use App\Enums\Ecosystem;
use App\Enums\LockfileFormat;
use App\Models\GitHubRunnerImage;
use App\Services\Lockfiles\ComponentData;
use App\Services\Lockfiles\GitHubWorkflowParser;
use App\Services\Lockfiles\UnreadableLockfileException;
use App\Support\LockfilePath;

const WORKFLOW = <<<'YAML'
name: CI
on:
  push:
    branches: [main]
jobs:
  test:
    runs-on: ${{ matrix.os }}
    strategy:
      matrix:
        os: [ubuntu-22.04, macos-13]
        include:
          - os: windows-2022
    steps:
      - uses: actions/checkout@v4
      - uses: Actions/Setup-Node@v4.1.0
        with:
          node-version: 22
      - uses: tj-actions/changed-files@0e58ed8671d6b60d0890c21b07f8835ace038e67 # v44.5.7
      - uses: github/codeql-action/init@v3
      - uses: ./.github/actions/setup
      - uses: docker://alpine:3.20
      - run: 'echo "uses: fake/action@v1"'
  deploy:
    needs: test
    uses: acme/ci/.github/workflows/deploy.yml@main
  lint:
    runs-on: [self-hosted, linux]
  release:
    runs-on:
      group: large
      labels: ubuntu-24.04-arm
    steps:
      - uses: actions/checkout@v4
YAML;

it('reads the actions and reusable workflows a workflow uses, at the reference written', function () {
    $components = (new GitHubWorkflowParser)->parse(WORKFLOW);

    expect(collect($components)->map(fn (ComponentData $component): string => "{$component->name}@{$component->version}")->all())->toBe([
        'actions/checkout@v4',
        'actions/setup-node@v4.1.0',
        'tj-actions/changed-files@0e58ed8671d6b60d0890c21b07f8835ace038e67',
        'github/codeql-action/init@v3',
        'acme/ci/.github/workflows/deploy.yml@main',
    ])
        ->and(collect($components)->pluck('ecosystem')->unique()->all())->toBe([Ecosystem::GitHubActions])
        ->and(collect($components)->pluck('isDev')->unique()->all())->toBe([false])
        ->and($components[0]->purl())->toBe('pkg:githubactions/actions/checkout@v4');
});

it('reads the steps of a composite action', function () {
    $components = (new GitHubWorkflowParser)->parse(<<<'YAML'
        name: Setup
        runs:
          using: composite
          steps:
            - uses: actions/cache@v4
            - uses: ./local
        YAML);

    expect(collect($components)->map(fn (ComponentData $component): string => "{$component->name}@{$component->version}")->all())->toBe(['actions/cache@v4']);
});

it('lists the runner labels of every job, with the values of a matrix and without expressions or groups', function () {
    expect((new GitHubWorkflowParser)->runnerLabels(WORKFLOW))->toBe(['ubuntu-22.04', 'macos-13', 'windows-2022', 'self-hosted', 'linux', 'ubuntu-24.04-arm']);
});

it('rejects a workflow that is not YAML', function () {
    (new GitHubWorkflowParser)->parse("jobs: [\n");
})->throws(UnreadableLockfileException::class);

it('tells workflows and local actions apart from other files of .github', function (string $path, bool $isWorkflow) {
    expect(LockfileFormat::forPath($path) === LockfileFormat::GitHubWorkflow)->toBe($isWorkflow)
        ->and(LockfilePath::isValid($path))->toBe($isWorkflow);
})->with([
    ['.github/workflows/ci.yml', true],
    ['.github/workflows/deploy.yaml', true],
    ['.github/actions/setup/action.yml', true],
    ['.github/actions/a/b/action.yaml', true],
    ['.github/workflows/nested/ci.yml', false],
    ['.github/dependabot.yml', false],
    ['.github/ISSUE_TEMPLATE/bug.yml', false],
    ['apps/api/.github/workflows/ci.yml', false],
    ['github-workflow', false],
]);

it('does not offer workflows for upload', function () {
    expect(Ecosystem::forLockfile('ci.yml'))->toBeNull()
        ->and(Ecosystem::forLockfile('github-workflow'))->toBeNull()
        ->and(array_keys(Ecosystem::lockfileNames()))->toBe(['composer.lock', 'Gemfile.lock', 'pnpm-lock.yaml', 'yarn.lock', 'package-lock.json']);
});

it('finds the runner image of a label', function (string $label, ?string $image) {
    $images = collect(['ubuntu-22.04', 'ubuntu-24.04-arm64', 'macos-14', 'macos-14-arm64', 'windows-2022'])
        ->mapWithKeys(fn (string $name): array => [$name => new GitHubRunnerImage(['name' => $name])]);

    expect(GitHubRunnerImage::forLabel($label, $images)?->name)->toBe($image);
})->with([
    ['ubuntu-22.04', 'ubuntu-22.04'],
    ['Ubuntu-22.04', 'ubuntu-22.04'],
    ['ubuntu-24.04-arm', 'ubuntu-24.04-arm64'],
    ['macos-14-xlarge', 'macos-14'],
    ['macos-14-large', 'macos-14'],
    ['ubuntu-latest', null],
    ['self-hosted', null],
    ['${{ matrix.os }}', null],
]);
