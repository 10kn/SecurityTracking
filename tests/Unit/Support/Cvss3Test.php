<?php

use App\Enums\Severity;
use App\Support\Cvss3;

it('computes the CVSS v3 base score of a vector', function (string $vector, float $score) {
    expect(Cvss3::baseScore($vector))->toBe($score);
})->with([
    ['CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:H/I:H/A:H', 9.8],
    ['CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:H/I:H/A:N', 9.1],
    ['CVSS:3.1/AV:L/AC:L/PR:N/UI:N/S:C/C:H/I:H/A:H', 9.3],
    ['CVSS:3.1/AV:N/AC:H/PR:N/UI:N/S:U/C:H/I:H/A:H', 8.1],
    ['CVSS:3.1/AV:L/AC:L/PR:L/UI:N/S:U/C:H/I:H/A:H', 7.8],
    ['CVSS:3.1/AV:L/AC:H/PR:N/UI:R/S:U/C:N/I:H/A:H', 6.3],
    ['CVSS:3.0/AV:N/AC:L/PR:L/UI:N/S:C/C:L/I:L/A:N', 6.4],
    ['CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:L/I:N/A:N', 5.3],
    ['CVSS:3.1/AV:P/AC:H/PR:H/UI:R/S:C/C:L/I:N/A:N', 1.8],
    ['CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:N/I:N/A:N', 0.0],
]);

it('returns null for anything but a complete CVSS v3 vector', function (string $vector) {
    expect(Cvss3::baseScore($vector))->toBeNull();
})->with([
    'CVSS v4' => 'CVSS:4.0/AV:N/AC:L/AT:N/PR:N/UI:N/VC:H/VI:H/VA:H/SC:N/SI:N/SA:N',
    'CVSS v2' => 'AV:N/AC:L/Au:N/C:P/I:P/A:P',
    'missing metric' => 'CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:H/I:H',
    'unknown value' => 'CVSS:3.1/AV:X/AC:L/PR:N/UI:N/S:U/C:H/I:H/A:H',
]);

it('rates a score like the CVSS specification', function (float $score, Severity $severity) {
    expect(Cvss3::severity($score))->toBe($severity);
})->with([
    [10.0, Severity::Critical],
    [9.0, Severity::Critical],
    [8.9, Severity::High],
    [7.0, Severity::High],
    [6.9, Severity::Medium],
    [4.0, Severity::Medium],
    [3.9, Severity::Low],
    [0.0, Severity::Low],
]);
