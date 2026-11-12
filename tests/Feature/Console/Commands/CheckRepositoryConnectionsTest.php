<?php

use App\Enums\ChatNotificationKind;
use App\Models\ChatNotification;
use App\Models\RepositoryConnection;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

use function Pest\Laravel\artisan;

beforeEach(function () {
    $this->travelTo('2026-11-12 06:00:00');
    fakeGoogleChat();
});

/**
 * @param  array<string, string>  $headers
 */
function fakeGitHubUser(int $status = 200, array $headers = ['X-OAuth-Scopes' => 'repo', 'GitHub-Authentication-Token-Expiration' => '2027-02-01 00:00:00 UTC']): void
{
    Http::fake(['https://api.github.com/user' => Http::response($status === 200 ? ['login' => 'dev-abc'] : ['message' => 'Bad credentials'], $status, $headers)]);
}

it('keeps what github says of each token', function () {
    $connection = RepositoryConnection::factory()->create(['account_login' => null, 'expires_at' => null, 'last_error' => 'old error']);
    fakeGitHubUser();

    artisan('psm:check-repository-connections')->assertSuccessful();

    expect($connection->fresh())
        ->account_login->toBe('dev-abc')
        ->expires_at->toEqual(now()->setDateTime(2027, 2, 1, 0, 0))
        ->last_checked_at->toEqual(now())
        ->last_error->toBeNull()
        ->and(ChatNotification::where('kind', ChatNotificationKind::AdminAlert)->count())->toBe(0);
});

it('tells the admin once when github turns a token down, and again for a new token', function () {
    $connection = RepositoryConnection::factory()->create(['name' => 'Khách ABC', 'token' => 'ghp_one']);
    fakeGitHubUser(401, []);

    artisan('psm:check-repository-connections')->assertSuccessful();
    artisan('psm:check-repository-connections')->assertSuccessful();

    expect($connection->fresh()->last_error)->toBe('GitHub không nhận token: token đã bị thu hồi, hết hạn hoặc sai.')
        ->and(chatMessagesTo('ADMIN'))->toHaveCount(1)
        ->and(chatJson(chatMessagesTo('ADMIN')->sole()))->toContain('GitHub không nhận token của kết nối Khách ABC');

    $connection->update(['token' => 'ghp_two']);
    artisan('psm:check-repository-connections')->assertSuccessful();

    expect(chatMessagesTo('ADMIN'))->toHaveCount(2);
});

it('tells the admin once when a token expires within two weeks', function () {
    RepositoryConnection::factory()->create(['name' => 'Khách ABC']);
    fakeGitHubUser(headers: ['X-OAuth-Scopes' => 'repo', 'GitHub-Authentication-Token-Expiration' => '2026-11-20 00:00:00 UTC']);

    artisan('psm:check-repository-connections')->assertSuccessful();
    $this->travel(1)->day();
    artisan('psm:check-repository-connections')->assertSuccessful();

    expect(chatMessagesTo('ADMIN'))->toHaveCount(1)
        ->and(chatJson(chatMessagesTo('ADMIN')->sole()))->toContain('Token của kết nối Khách ABC sắp hết hạn');
});

it('fails and records nothing when github cannot be reached', function () {
    $connection = RepositoryConnection::factory()->create(['last_checked_at' => null]);
    Sleep::fake();
    fakeGitHubUser(503, []);

    artisan('psm:check-repository-connections')->assertFailed();

    expect($connection->fresh()->last_checked_at)->toBeNull();
});

it('runs once a day before the lockfiles are read', function () {
    $event = collect(app(Schedule::class)->events())->first(fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'psm:check-repository-connections'));

    expect($event->expression)->toBe('0 6 * * *')
        ->and($event->timezone)->toBe('Asia/Ho_Chi_Minh');
});
