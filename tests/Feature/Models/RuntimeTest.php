<?php

use App\Enums\RuntimeStatus;
use App\Models\Deployment;
use App\Models\Runtime;
use App\Models\Technology;
use Illuminate\Database\UniqueConstraintViolationException;

it('allows one runtime per technology on a deployment', function () {
    $deployment = Deployment::factory()->create();
    $php = Technology::factory()->php()->create();
    Runtime::factory()->for($deployment)->for($php)->create();

    expect(fn () => Runtime::factory()->for($deployment)->for($php)->create())
        ->toThrow(UniqueConstraintViolationException::class);
});

it('allows runtimes of different technologies on the same deployment', function () {
    $deployment = Deployment::factory()->create();

    Runtime::factory()->for($deployment)->create();
    Runtime::factory()->for($deployment)->create();

    expect($deployment->runtimes()->count())->toBe(2);
});

it('marks a new runtime as unknown until it is evaluated', function () {
    $deployment = Deployment::factory()->create();

    $runtime = $deployment->runtimes()->create([
        'technology_id' => Technology::factory()->php()->create()->id,
        'raw_version' => '8.3.12',
        'source' => 'manual',
        'observed_at' => now(),
    ])->refresh();

    expect($runtime->status)->toBe(RuntimeStatus::Unknown)
        ->and($runtime->asset_id)->toBeNull();
});
