<?php

use App\Models\Finding;
use Illuminate\Database\UniqueConstraintViolationException;

it('scopes open findings and excludes resolved ones', function () {
    $open = Finding::factory()->create();
    Finding::factory()->resolved()->create();

    expect(Finding::open()->pluck('id')->all())->toBe([$open->id]);
});

it('rejects a second finding with the same fingerprint', function () {
    $finding = Finding::factory()->create();

    expect(fn () => Finding::factory()->create(['fingerprint' => $finding->fingerprint]))
        ->toThrow(UniqueConstraintViolationException::class);
});
