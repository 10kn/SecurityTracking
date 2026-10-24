<?php

use App\Enums\RuntimeStatus;
use App\Models\Runtime;

use function Pest\Laravel\artisan;

it('re-evaluates every runtime so a branch turns end of life on its date', function () {
    $this->travelTo('2026-10-23 10:00:00');
    $python = syncedTechnology('python');
    $python->branches()->where('branch', '3.14')->update(['security_support_until' => '2026-10-23']);
    $runtime = Runtime::factory()->for($python)->create(['raw_version' => '3.14.9']);
    artisan('psm:evaluate-runtimes')->assertSuccessful();
    expect($runtime->refresh()->status)->toBe(RuntimeStatus::Ok);

    $this->travelTo('2026-10-24 00:05:00');
    $python->catalogHeartbeat()->update(['last_success_at' => now()]);
    artisan('psm:evaluate-runtimes')->assertSuccessful();

    expect($runtime->refresh()->status)->toBe(RuntimeStatus::Eol);
});
