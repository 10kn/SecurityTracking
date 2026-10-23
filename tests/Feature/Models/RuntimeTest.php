<?php

use App\Enums\Language;
use App\Enums\RuntimeStatus;
use App\Models\Deployment;
use App\Models\Runtime;
use Illuminate\Database\UniqueConstraintViolationException;

it('allows one runtime per language on a deployment', function () {
    $deployment = Deployment::factory()->create();
    Runtime::factory()->for($deployment)->create(['language' => Language::Php]);

    expect(fn () => Runtime::factory()->for($deployment)->create(['language' => Language::Php]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('allows runtimes of different languages on the same deployment', function () {
    $deployment = Deployment::factory()->create();

    Runtime::factory()->for($deployment)->create(['language' => Language::Php]);
    Runtime::factory()->for($deployment)->create(['language' => Language::Nodejs]);

    expect($deployment->runtimes()->count())->toBe(2);
});

it('marks a new runtime as unknown until it is evaluated', function () {
    $deployment = Deployment::factory()->create();

    $runtime = $deployment->runtimes()->create([
        'language' => Language::Php,
        'raw_version' => '8.3.12',
        'source' => 'manual',
        'observed_at' => now(),
    ])->refresh();

    expect($runtime->status)->toBe(RuntimeStatus::Unknown)
        ->and($runtime->asset_id)->toBeNull();
});
