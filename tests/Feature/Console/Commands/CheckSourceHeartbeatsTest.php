<?php

use App\Models\SourceHeartbeat;

use function Pest\Laravel\artisan;

beforeEach(function () {
    fakeGoogleChat();
});

it('alerts the admin space once per outage of a source', function () {
    SourceHeartbeat::factory()->stale()->create(['name' => 'Danh mục version PHP']);
    SourceHeartbeat::factory()->create(['name' => 'Danh mục version Ruby']);

    artisan('psm:check-sources')->assertSuccessful();
    artisan('psm:check-sources')->assertSuccessful();

    expect(chatMessagesTo('ADMIN'))->toHaveCount(1)
        ->and(chatJson(chatMessagesTo('ADMIN')->sole()))->toContain('Nguồn Danh mục version PHP lỗi quá ngưỡng', 'cURL error 28')->not->toContain('Ruby');
});

it('alerts again when the source fails again after recovering', function () {
    $heartbeat = SourceHeartbeat::factory()->stale()->create();
    artisan('psm:check-sources');

    $heartbeat->update(['last_success_at' => now()]);
    artisan('psm:check-sources');
    $this->travel($heartbeat->threshold_minutes + 1)->minutes();
    artisan('psm:check-sources');

    expect(chatMessagesTo('ADMIN'))->toHaveCount(2);
});

it('alerts about a source that never synced', function () {
    SourceHeartbeat::factory()->create(['last_success_at' => null, 'last_failure_at' => now()]);

    artisan('psm:check-sources')->assertSuccessful();

    expect(chatJson(chatMessagesTo('ADMIN')->sole()))->toContain('Thành công gần nhất: chưa có');
});
