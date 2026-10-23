<?php

use App\Filament\Resources\Teams\Pages\CreateTeam;
use App\Filament\Resources\Teams\Pages\EditTeam;
use App\Filament\Resources\Teams\Pages\ListTeams;
use App\Models\ChatNotification;
use App\Models\Project;
use App\Models\Team;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

use function Pest\Laravel\assertDatabaseHas;

it('lists teams with their google chat status', function () {
    actingAsAdmin();
    $failing = Team::factory()->withGoogleChatWebhook()->create(['name' => 'Booking']);
    ChatNotification::factory()->for($failing)->failed()->create();
    $unconfigured = Team::factory()->create(['name' => 'Internal Tools']);

    Livewire::test(ListTeams::class)
        ->assertCanSeeTableRecords([$failing, $unconfigured])
        ->assertSeeText('Lỗi gửi')
        ->assertSeeText('Chưa cấu hình');
});

it('creates a team and suggests the slug from the name', function () {
    actingAsAdmin();

    Livewire::test(CreateTeam::class)
        ->fillForm(['name' => 'Internal Tools'])
        ->assertSchemaStateSet(['slug' => 'internal-tools'])
        ->call('create')
        ->assertHasNoFormErrors();

    assertDatabaseHas(Team::class, ['name' => 'Internal Tools', 'slug' => 'internal-tools']);
});

it('rejects a slug that is not lowercase letters, digits and hyphens', function (string $slug) {
    actingAsAdmin();

    Livewire::test(CreateTeam::class)
        ->fillForm(['name' => 'Payments', 'slug' => $slug])
        ->call('create')
        ->assertHasFormErrors(['slug' => 'regex']);
})->with([
    'space' => 'pay ments',
    'uppercase' => 'Payments',
    'trailing hyphen' => 'payments-',
    'underscore' => 'pay_ments',
]);

it('rejects a slug another team already uses', function () {
    actingAsAdmin();
    Team::factory()->create(['slug' => 'payments']);

    Livewire::test(CreateTeam::class)
        ->fillForm(['name' => 'Payments', 'slug' => 'payments'])
        ->call('create')
        ->assertHasFormErrors(['slug' => 'unique']);
});

it('shows only a masked webhook on the edit page', function () {
    actingAsAdmin();
    $team = Team::factory()->withGoogleChatWebhook()->create();

    Livewire::test(EditTeam::class, ['record' => $team->getRouteKey()])
        ->assertSee('https://chat.googleapis.com/v1/spaces/DEMO/messages?key=••••')
        ->assertDontSee('demo-key')
        ->assertDontSee('demo-token');
});

it('sends a test message to the saved webhook', function () {
    actingAsAdmin();
    Http::preventStrayRequests();
    Http::fake(['https://chat.googleapis.com/v1/spaces/DEMO/messages*' => Http::response(['name' => 'spaces/DEMO/messages/1'])]);
    $team = Team::factory()->withGoogleChatWebhook()->create();

    Livewire::test(EditTeam::class, ['record' => $team->getRouteKey()])
        ->callAction(TestAction::make('sendTestMessage')->schemaComponent('google_chat_actions', schema: 'form'))
        ->assertNotified('Gửi thử thành công.');

    Http::assertSentCount(1);
});

it('saves a new webhook only after google chat accepts the test message', function () {
    actingAsAdmin();
    Http::preventStrayRequests();
    Http::fake(['https://chat.googleapis.com/v1/spaces/NEW/messages*' => Http::response(['name' => 'spaces/NEW/messages/1'])]);
    $team = Team::factory()->create();
    $webhookUrl = 'https://chat.googleapis.com/v1/spaces/NEW/messages?key=new-key&token=new-token';

    Livewire::test(EditTeam::class, ['record' => $team->getRouteKey()])
        ->callAction(TestAction::make('replaceWebhook')->schemaComponent('google_chat_actions', schema: 'form'), data: ['google_chat_webhook_url' => $webhookUrl])
        ->assertHasNoFormErrors()
        ->assertNotified('Đã lưu webhook và gửi thử thành công.');

    expect($team->refresh()->google_chat_webhook_url)->toBe($webhookUrl);
    Http::assertSent(fn (Request $request): bool => $request->url() === $webhookUrl);
});

it('keeps the old webhook when the new one fails the test message', function () {
    actingAsAdmin();
    Http::preventStrayRequests();
    Http::fake(['https://chat.googleapis.com/v1/spaces/NEW/messages*' => Http::response('Not found', 404)]);
    $team = Team::factory()->withGoogleChatWebhook()->create();
    $savedWebhookUrl = $team->google_chat_webhook_url;

    Livewire::test(EditTeam::class, ['record' => $team->getRouteKey()])
        ->callAction(TestAction::make('replaceWebhook')->schemaComponent('google_chat_actions', schema: 'form'), data: [
            'google_chat_webhook_url' => 'https://chat.googleapis.com/v1/spaces/NEW/messages?key=new-key&token=new-token',
        ])
        ->assertNotified('Gửi thử thất bại, webhook chưa được lưu');

    expect($team->refresh()->google_chat_webhook_url)->toBe($savedWebhookUrl);
});

it('rejects a webhook outside google chat without sending anything', function (string $webhookUrl) {
    actingAsAdmin();
    Http::preventStrayRequests();
    Http::fake();
    $team = Team::factory()->create();

    Livewire::test(EditTeam::class, ['record' => $team->getRouteKey()])
        ->callAction(TestAction::make('replaceWebhook')->schemaComponent('google_chat_actions', schema: 'form'), data: ['google_chat_webhook_url' => $webhookUrl])
        ->assertHasFormErrors(['google_chat_webhook_url']);

    Http::assertNothingSent();
    expect($team->refresh()->google_chat_webhook_url)->toBeNull();
})->with([
    'other host' => 'https://chat.googleapis.com.attacker.example/v1/spaces/X/messages',
    'plain http' => 'http://chat.googleapis.com/v1/spaces/X/messages',
    'internal address' => 'http://169.254.169.254/latest/meta-data',
]);

it('disables deleting a team that still owns projects', function () {
    actingAsAdmin();
    $team = Team::factory()->has(Project::factory())->create();

    Livewire::test(EditTeam::class, ['record' => $team->getRouteKey()])
        ->assertActionDisabled(DeleteAction::class);
});

it('deletes a team without projects', function () {
    actingAsAdmin();
    $team = Team::factory()->create();

    Livewire::test(EditTeam::class, ['record' => $team->getRouteKey()])
        ->callAction(DeleteAction::class);

    expect(Team::find($team->id))->toBeNull();
});
