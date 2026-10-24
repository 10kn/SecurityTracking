<?php

use App\Models\ChatNotification;
use App\Models\Project;
use App\Models\Team;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('stores the google chat webhook encrypted', function () {
    $team = Team::factory()->withGoogleChatWebhook()->create();

    $storedValue = DB::table('teams')->where('id', $team->id)->value('google_chat_webhook_url');

    expect($storedValue)->not->toContain('chat.googleapis.com')
        ->and($team->refresh()->google_chat_webhook_url)->toStartWith('https://chat.googleapis.com/v1/spaces/');
});

it('hides the google chat webhook when serialized', function () {
    $team = Team::factory()->withGoogleChatWebhook()->create();

    expect($team->toArray())->not->toHaveKey('google_chat_webhook_url');
});

it('refuses to delete a team that still owns projects', function () {
    $team = Team::factory()->has(Project::factory())->create();

    expect(fn () => $team->delete())->toThrow(QueryException::class);
});

it('ignores a message still being retried in the google chat status', function () {
    $team = Team::factory()->withGoogleChatWebhook()->create();
    ChatNotification::factory()->for($team)->failed()->create();
    ChatNotification::factory()->for($team)->create(['last_error' => 'Google Chat trả lỗi HTTP 503.']);

    expect($team->googleChatStatus()->getLabel())->toBe('Lỗi gửi');
});
