<?php

use App\Enums\RuntimeStatus;
use App\Enums\Technology;
use App\Models\RuntimeBranch;
use App\Services\RuntimeEvaluator;

/*
 * The fixtures are a snapshot from 2026-10-23, so every test evaluates on that day.
 */

it('evaluates a version against the synced catalog', function (string $technology, ?string $version, RuntimeStatus $status, string $reason) {
    $this->travelTo('2026-10-23 10:00:00');

    $evaluation = (new RuntimeEvaluator)->evaluate(syncedTechnology(Technology::from($technology)), $version);

    expect($evaluation->status)->toBe($status)
        ->and($evaluation->reasonText())->toBe($reason);
})->with([
    'php latest of a branch' => ['php', '8.3.36', RuntimeStatus::Ok, 'Đang dùng bản mới nhất của nhánh 8.3'],
    'php behind a security release' => ['php', '8.3.35', RuntimeStatus::OutdatedSecurity, 'Đã có bản vá bảo mật, bản mới nhất của nhánh là 8.3.36'],
    'php debian package version' => ['php', '8.3.12-1+deb.sury.org+1', RuntimeStatus::OutdatedSecurity, 'Đã có bản vá bảo mật, bản mới nhất của nhánh là 8.3.36'],
    'php end of life' => ['php', '8.1.34', RuntimeStatus::Eol, 'Nhánh 8.1 hết hỗ trợ từ 2025-12-31'],
    'php branch only' => ['php', '8.3', RuntimeStatus::Unknown, 'Version 8.3 chưa đủ chi tiết, cần dạng 8.3.36'],
    'php newer than any release' => ['php', '8.3.99', RuntimeStatus::Unknown, 'Version 8.3.99 mới hơn bản mới nhất đã biết (8.3.36)'],
    'php release php.net never published' => ['php', '8.3.34', RuntimeStatus::Unknown, 'Không có version 8.3.34 trong danh mục php.net'],
    'php unknown branch' => ['php', '9.0.1', RuntimeStatus::Unknown, 'Không có nhánh của 9.0.1 trong danh mục PHP'],
    'php unreadable' => ['php', 'latest', RuntimeStatus::Unknown, 'Không đọc được version "latest"'],
    'php blank' => ['php', null, RuntimeStatus::Unknown, 'Chưa nhập version'],
    'node only newer non-security releases' => ['nodejs', '24.21.0', RuntimeStatus::Outdated, 'Có bản mới hơn: 24.22.0'],
    'node latest with v prefix' => ['nodejs', 'v24.22.0', RuntimeStatus::Ok, 'Đang dùng bản mới nhất của nhánh 24'],
    'node behind a security release' => ['nodejs', '22.23.3', RuntimeStatus::OutdatedSecurity, 'Đã có bản vá bảo mật, bản mới nhất của nhánh là 22.23.4'],
    'node end of life' => ['nodejs', '20.18.0', RuntimeStatus::Eol, 'Nhánh 20 hết hỗ trợ từ 2026-04-30'],
    'ruby latest from ruby-lang.org' => ['ruby', '3.4.12', RuntimeStatus::Ok, 'Đang dùng bản mới nhất của nhánh 3.4'],
    'ruby latest on endoflife.date but not on ruby-lang.org' => ['ruby', '3.4.11', RuntimeStatus::OutdatedSecurity, 'Đã có bản vá bảo mật, bản mới nhất của nhánh là 3.4.12'],
    'ruby newer release with unknown flag' => ['ruby', '3.3.6', RuntimeStatus::OutdatedSecurity, 'Đã có bản vá bảo mật, bản mới nhất của nhánh là 3.3.13'],
    'ruby end of life' => ['ruby', '3.2.11', RuntimeStatus::Eol, 'Nhánh 3.2 hết hỗ trợ từ 2026-03-31'],
]);

it('reports the normalized version and branch', function () {
    $this->travelTo('2026-10-23 10:00:00');

    $evaluation = (new RuntimeEvaluator)->evaluate(syncedTechnology(Technology::Nodejs), 'v22.23.3');

    expect($evaluation->version)->toBe('22.23.3')
        ->and($evaluation->branch)->toBe('22');
});

it('turns unknown instead of keeping the old result when the catalog stops syncing', function () {
    $this->travelTo('2026-10-23 10:00:00');
    $php = syncedTechnology(Technology::Php);

    $this->travel(config('psm.catalog.stale_after_minutes') + 1)->minutes();

    expect((new RuntimeEvaluator)->evaluate($php, '8.3.36')->status)->toBe(RuntimeStatus::Unknown);
});

it('evaluates as unknown before the first sync', function () {
    $evaluation = (new RuntimeEvaluator)->evaluate(Technology::Php, '8.3.36');

    expect($evaluation->status)->toBe(RuntimeStatus::Unknown)
        ->and($evaluation->reasonText())->toBe('Danh mục version PHP chưa đồng bộ hoặc đang lỗi');
});

it('marks a branch end of life on the day after its end date', function () {
    $this->travelTo('2026-10-23 10:00:00');
    $php = syncedTechnology(Technology::Php);
    RuntimeBranch::where('technology', $php)->where('branch', '8.3')->update(['security_support_until' => '2026-10-23']);

    expect((new RuntimeEvaluator)->evaluate($php, '8.3.36')->status)->toBe(RuntimeStatus::Ok);

    $this->travelTo('2026-10-24 00:05:00');
    $php->catalogHeartbeat()->update(['last_success_at' => now()]);

    expect((new RuntimeEvaluator)->evaluate($php, '8.3.36')->status)->toBe(RuntimeStatus::Eol);
});
