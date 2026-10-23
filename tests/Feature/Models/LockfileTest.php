<?php

use App\Models\Deployment;
use App\Models\Lockfile;
use Illuminate\Database\UniqueConstraintViolationException;

it('allows one lockfile per path on a deployment', function () {
    $deployment = Deployment::factory()->create();
    Lockfile::factory()->for($deployment)->create(['path' => 'composer.lock']);

    expect(fn () => Lockfile::factory()->for($deployment)->create(['path' => 'composer.lock']))
        ->toThrow(UniqueConstraintViolationException::class);
});
