<?php

use App\Enums\LinuxDistribution;
use App\Services\OsAdvisories\OsReleaseSupport;

beforeEach(function () {
    $this->travelTo('2026-11-09 10:00:00');
});

it('knows the releases in standard support by version or Debian codename', function () {
    fakeOsAdvisoryFeeds();
    $support = app(OsReleaseSupport::class);
    $support->refresh(LinuxDistribution::Debian);
    $support->refresh(LinuxDistribution::Ubuntu);

    expect($support->isSupported(LinuxDistribution::Debian, 'trixie'))->toBeTrue()
        ->and($support->isSupported(LinuxDistribution::Debian, '12'))->toBeTrue()
        ->and($support->isSupported(LinuxDistribution::Debian, 'bullseye'))->toBeFalse()
        ->and($support->isSupported(LinuxDistribution::Debian, 'sid'))->toBeFalse()
        ->and($support->versionOf(LinuxDistribution::Debian, 'trixie'))->toBe('13')
        ->and($support->isSupported(LinuxDistribution::Ubuntu, '22.04'))->toBeTrue()
        ->and($support->isSupported(LinuxDistribution::Ubuntu, '25.10'))->toBeFalse()
        ->and($support->isSupported(LinuxDistribution::Ubuntu, '20.04'))->toBeFalse();
});

it('counts every release before endoflife.date was ever read, so nothing is missed', function () {
    expect(app(OsReleaseSupport::class)->isSupported(LinuxDistribution::Ubuntu, '20.04'))->toBeTrue();
});
