<?php

use App\Models\ChatNotification;
use Illuminate\Database\UniqueConstraintViolationException;

it('rejects a second message with the same dedupe key', function () {
    $notification = ChatNotification::factory()->create();

    expect(fn () => ChatNotification::factory()->create(['dedupe_key' => $notification->dedupe_key]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('logs admin space messages without a team', function () {
    $notification = ChatNotification::factory()->forAdmin()->create();

    expect($notification->refresh()->team)->toBeNull();
});
