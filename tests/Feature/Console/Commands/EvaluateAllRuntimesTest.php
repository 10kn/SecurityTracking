<?php

use App\Enums\RuntimeStatus;
use App\Enums\Technology;
use App\Models\Runtime;
use App\Models\RuntimeBranch;

use function Pest\Laravel\artisan;

it('re-evaluates every runtime so a branch turns end of life on its date', function () {
    $this->travelTo('2026-10-23 10:00:00');
    $php = syncedTechnology(Technology::Php);
    RuntimeBranch::where('technology', $php)->where('branch', '8.3')->update(['security_support_until' => '2026-10-23']);
    $runtime = Runtime::factory()->create(['technology' => $php, 'raw_version' => '8.3.36']);
    artisan('psm:evaluate-runtimes')->assertSuccessful();
    expect($runtime->refresh()->status)->toBe(RuntimeStatus::Ok);

    $this->travelTo('2026-10-24 00:05:00');
    $php->catalogHeartbeat()->update(['last_success_at' => now()]);
    artisan('psm:evaluate-runtimes')->assertSuccessful();

    expect($runtime->refresh()->status)->toBe(RuntimeStatus::Eol);
});
