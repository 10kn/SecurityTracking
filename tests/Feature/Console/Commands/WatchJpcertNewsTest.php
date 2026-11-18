<?php

use Anthropic\Client;
use App\Enums\ChatNotificationKind;
use App\Jobs\SendChatNotification;
use App\Models\ChatNotification;
use App\Models\SecurityNewsArticle;
use App\Models\SourceHeartbeat;
use App\Services\Notices\JpcertNewsFeed;
use App\Services\SystemSettings;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;

use function Pest\Laravel\artisan;
use function Pest\Laravel\travelTo;

function jpcertIndex(string $rows): string
{
    $rows = $rows ?: '<tr><td>2026-01-01</td><td><a href="/at/2026/at260001.html">Archived advisory</a></td></tr>';

    return "<article><table><tr><th>公開日</th><th>タイトル</th></tr>{$rows}</table></article>";
}

function jpcertPost(string $title, string $body): string
{
    return "<div class=\"page_title_area\"><h3>{$title}</h3></div><article>{$body}</article>";
}

/** @param Closure(array<string, mixed>): array<string, mixed> $answer */
function fakeJpcertClaude(Closure $answer): void
{
    $transport = Mockery::mock(ClientInterface::class);
    $transport->shouldReceive('sendRequest')->andReturnUsing(function (RequestInterface $request) use ($answer): Response {
        $body = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $data = json_decode($body['messages'][0]['content'], true, flags: JSON_THROW_ON_ERROR);

        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'id' => 'msg_jpcert',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-haiku-5-5',
            'content' => [['type' => 'text', 'text' => json_encode($answer($data), JSON_THROW_ON_ERROR)]],
            'stop_reason' => 'end_turn',
            'stop_sequence' => null,
            'usage' => ['input_tokens' => 900, 'output_tokens' => 120],
        ], JSON_THROW_ON_ERROR));
    });
    app()->instance(Client::class, new Client(apiKey: 'test-key', requestOptions: ['transporter' => $transport, 'maxRetries' => 0]));
}

/** @return array<string, mixed> */
function acceptedJpcertAnswer(string $tag = 'OpenSSH'): array
{
    return [
        'relevant' => true,
        'matches' => [['tag' => $tag, 'evidence' => 'Certain sshd versions are affected.']],
        'action_evidence' => 'Install the security update immediately.',
        'summary' => 'Cập nhật cho máy chủ SSH đang chạy phiên bản bị ảnh hưởng.',
        'reason' => 'Nguồn tin yêu cầu cài bản cập nhật ngay.',
    ];
}

/** @return array<string, mixed> */
function ignoredJpcertAnswer(): array
{
    return ['relevant' => false, 'matches' => [], 'summary' => '', 'reason' => '', 'action_evidence' => ''];
}

it('leaves JPCERT polling inactive when the admin has no tags', function () {
    Http::preventStrayRequests();
    Http::fake();

    artisan('psm:watch-jpcert')->assertSuccessful();

    Http::assertNothingSent();
    expect(SourceHeartbeat::count())->toBe(0);
});

it('backfills relevant articles silently and sends new semantic matches only to the admin space once', function () {
    travelTo(now()->setDate(2026, 11, 18)->setTime(9, 0));
    config([
        'psm.jpcert.tags' => ['OpenSSH', 'Amazon ECS', 'PHP'],
        'psm.notices.classifier.api_key' => 'test-key',
        'psm.alerts.admin_webhook' => chatWebhook('ADMIN'),
        'psm.alerts.news_webhook' => chatWebhook('NEWS'),
    ]);
    Queue::fake([SendChatNotification::class]);
    Http::preventStrayRequests();
    $origin = JpcertNewsFeed::ORIGIN;
    $historical = '<tr><td>2026-11-09</td><td><a href="2026110901.html">OpenSSHにおける認証回避</a></td></tr>';
    $listing = $historical.'<tr><td>2026-11-18</td><td><a href="2026111801.html">sshd advisory</a></td></tr>'
        .'<tr><td>2026-11-18</td><td><a href="2026111802.html">Third-party advisory</a></td></tr>'
        .'<tr><td>2026-11-18</td><td><a href="https://untrusted.example/newsflash/2026111803.html">Untrusted link</a></td></tr>';
    $title = 'OpenSSHにおける認証回避 (CVE-2026-123158)';
    $postText = '<p>Certain sshd versions are affected.</p><p>Install the security update immediately.</p>';
    Http::fake([
        "{$origin}/at/2026.html" => Http::response(jpcertIndex('<tr><td>2026-10-29</td><td><a href="/at/2026/at260033.html">PHP advisory</a></td></tr>')),
        "{$origin}/at/2026/at260033.html" => Http::response(jpcertPost('PHP advisory', '2026-10-22（公開）<br>PHP runtime vulnerability')),
        "{$origin}/newsflash/" => Http::sequence()->push(jpcertIndex($historical))->push(jpcertIndex($listing))->push(jpcertIndex($listing)),
        "{$origin}/newsflash/2026110901.html" => Http::response(jpcertPost($title, $postText)),
        "{$origin}/newsflash/2026111801.html" => Http::response(jpcertPost('sshd advisory', $postText)),
        "{$origin}/newsflash/2026111802.html" => Http::response(jpcertPost('Third-party advisory', '<p>Amazon ECS is an example hosting environment.</p>')),
    ]);

    $classifiedTitles = [];
    fakeJpcertClaude(function (array $input) use (&$classifiedTitles, $title): array {
        $classifiedTitles[] = $input['title'];
        expect($input['watch_tags'])->toBe(['OpenSSH', 'Amazon ECS', 'PHP']);

        return in_array($input['title'], [$title, 'sshd advisory'], true) ? acceptedJpcertAnswer() : ignoredJpcertAnswer();
    });

    artisan('psm:watch-jpcert')->assertSuccessful();
    expect(ChatNotification::count())->toBe(0);
    expect(SecurityNewsArticle::where('title', $title)->firstOrFail()->cve_ids)->toBe(['CVE-2026-123158']);

    artisan('psm:watch-jpcert')->assertSuccessful();
    artisan('psm:watch-jpcert')->assertSuccessful();

    expect($classifiedTitles)->toBe([$title, 'PHP advisory', 'sshd advisory', 'Third-party advisory']);
    expect(SecurityNewsArticle::whereNotNull('accepted_at')->count())->toBe(2);
    expect(SecurityNewsArticle::where('title', 'PHP advisory')->firstOrFail()->published_on->toDateString())->toBe('2026-10-22');
    $article = SecurityNewsArticle::where('title', 'sshd advisory')->firstOrFail();
    expect($article->matched_tags)->toBe(['OpenSSH']);
    expect($article->cve_ids)->toBe([]);
    expect($article->is_backfill)->toBeFalse();
    $notification = ChatNotification::sole();
    expect($notification->kind)->toBe(ChatNotificationKind::JpcertNews);
    expect($notification->team_id)->toBeNull();
    expect($notification->webhookUrl())->toBe(chatWebhook('ADMIN'));
    expect(json_encode($notification->payload, JSON_UNESCAPED_SLASHES))->toContain(route('admin.security-news.show', $article));
    Queue::assertPushed(SendChatNotification::class, 1);
});

it('retries invalid AI evidence and can re-evaluate a dismissed article after the watch tags change', function () {
    travelTo(now()->setDate(2026, 11, 18)->setTime(9, 0));
    config(['psm.jpcert.tags' => ['GitHub'], 'psm.notices.classifier.api_key' => 'test-key']);
    Http::preventStrayRequests();
    $origin = JpcertNewsFeed::ORIGIN;
    Http::fake([
        "{$origin}/at/2026.html" => Http::response(jpcertIndex('')),
        "{$origin}/newsflash/" => Http::response(jpcertIndex('<tr><td>2026-11-18</td><td><a href="2026111801.html">sshd advisory</a></td></tr>')),
        "{$origin}/newsflash/2026111801.html" => Http::response(jpcertPost('sshd advisory', '<p>Certain sshd versions are affected.</p><p>Install the security update immediately.</p>')),
    ]);

    $attempts = 0;
    fakeJpcertClaude(function (array $input) use (&$attempts): array {
        $attempts++;

        return match ($attempts) {
            1 => acceptedJpcertAnswer('Invented product'),
            2 => ignoredJpcertAnswer(),
            3 => [...acceptedJpcertAnswer(), 'action_evidence' => 'A fabricated passage that cannot be verified.'],
            default => acceptedJpcertAnswer(),
        };
    });

    artisan('psm:watch-jpcert')->assertFailed();
    expect(SecurityNewsArticle::sole()->classification_key)->toBeNull();
    expect(SourceHeartbeat::firstWhere('source', 'jpcert:classification')->last_error)->toContain('unknown tag');

    artisan('psm:watch-jpcert')->assertSuccessful();
    expect(SecurityNewsArticle::sole()->accepted_at)->toBeNull();
    expect(SourceHeartbeat::firstWhere('source', 'jpcert:classification')->last_error)->toBeNull();

    config(['psm.jpcert.tags' => ['OpenSSH']]);
    artisan('psm:watch-jpcert')->assertFailed();
    expect(SecurityNewsArticle::sole()->accepted_at)->toBeNull();

    artisan('psm:watch-jpcert')->assertSuccessful();
    expect(SecurityNewsArticle::sole()->matched_tags)->toBe(['OpenSSH']);
});

it('reports a broken index without blocking the other section and parks an unreadable article after three attempts', function () {
    travelTo(now()->setDate(2026, 11, 18)->setTime(9, 0));
    config(['psm.jpcert.tags' => ['OpenSSH'], 'psm.notices.classifier.api_key' => 'test-key']);
    Http::preventStrayRequests();
    $origin = JpcertNewsFeed::ORIGIN;
    $unreadableUrl = "{$origin}/newsflash/2026111801.html";
    Http::fake([
        "{$origin}/at/2026.html" => Http::sequence()
            ->push(jpcertIndex('<tr><td>2026年11月18日</td><td><a href="/at/2026/at260038.html">New date format</a></td></tr>'))
            ->whenEmpty(Http::response(jpcertIndex(''))),
        "{$origin}/newsflash/" => Http::response(jpcertIndex(
            '<tr><td>2026-11-18</td><td><a href="2026111801.html">Missing news</a></td></tr>'
            .'<tr><td>2026-11-18</td><td><a href="./2026111802.html">sshd advisory</a></td></tr>',
        )),
        $unreadableUrl => Http::response('', 404),
        "{$origin}/newsflash/2026111802.html" => Http::response(jpcertPost('sshd advisory', '<p>Certain sshd versions are affected.</p><p>Install the security update immediately.</p>')),
    ]);
    fakeJpcertClaude(fn (array $input): array => acceptedJpcertAnswer());

    artisan('psm:watch-jpcert')->assertFailed();
    expect(SourceHeartbeat::firstWhere('source', 'jpcert:at')->last_error)->toContain('no recognizable article dates');
    expect(SourceHeartbeat::firstWhere('source', 'jpcert:classification')->last_success_at)->not->toBeNull();
    expect(SecurityNewsArticle::where('title', 'sshd advisory')->firstOrFail()->accepted_at)->not->toBeNull();

    artisan('psm:watch-jpcert')->assertFailed();
    artisan('psm:watch-jpcert')->assertFailed();
    artisan('psm:watch-jpcert')->assertSuccessful();

    expect(SecurityNewsArticle::where('title', 'Missing news')->firstOrFail()->attempts)->toBe(3);
    expect(Http::recorded(fn ($request) => $request->url() === $unreadableUrl))->toHaveCount(3);
});

it('silently backfills news during a pause and notifies for a previously rejected advisory re-listed as an urgent update', function () {
    travelTo(now()->setDate(2026, 11, 10)->setTime(9, 0));
    $settings = app(SystemSettings::class);
    $admin = actingAsAdmin();
    $settings->updateWebhook('admin_webhook', chatWebhook('ADMIN'), $admin);
    $settings->update(['jpcert_tags' => ['OpenSSH']], $admin);
    config(['psm.notices.classifier.api_key' => 'test-key']);

    Queue::fake([SendChatNotification::class]);
    Http::preventStrayRequests();
    $origin = JpcertNewsFeed::ORIGIN;
    $oldFlash = '<tr><td>2026-11-09</td><td><a href="2026110901.html">Existing flash</a></td></tr>';
    $duringPause = $oldFlash.'<tr><td>2026-11-16</td><td><a href="2026111601.html">Published while paused</a></td></tr>';
    $originalAlert = '<tr><td>2026-11-08</td><td><a href="/at/2026/at260038.html">Original advisory</a></td></tr>';
    $post = '<p>Certain sshd versions are affected.</p><p>Install the security update immediately.</p>';
    Http::fake([
        "{$origin}/at/2026.html" => Http::sequence()->push(jpcertIndex($originalAlert))->push(jpcertIndex($originalAlert))
            ->push(jpcertIndex('<tr><td>2026-11-18</td><td><a href="/at/2026/at260038.html">Urgent update to older advisory</a></td></tr>')),
        "{$origin}/newsflash/" => Http::sequence()->push(jpcertIndex($oldFlash))->push(jpcertIndex($duringPause))->push(jpcertIndex($duringPause)),
        "{$origin}/newsflash/2026110901.html" => Http::response(jpcertPost('Existing flash', $post)),
        "{$origin}/newsflash/2026111601.html" => Http::response(jpcertPost('Published while paused', $post)),
        "{$origin}/at/2026/at260038.html" => Http::sequence()
            ->push(jpcertPost('Original advisory', '2026-10-01（公開）<br>Routine update.'))
            ->push(jpcertPost('Urgent update to older advisory', '2026-10-01（公開）<br>'.$post)),
    ]);
    fakeJpcertClaude(fn (array $input): array => $input['title'] === 'Original advisory' ? ignoredJpcertAnswer() : acceptedJpcertAnswer());
    artisan('psm:watch-jpcert')->assertSuccessful();

    $settings->update(['jpcert_tags' => []], $admin);
    travelTo(now()->setDate(2026, 11, 18)->setTime(9, 0));
    artisan('psm:check-sources')->assertSuccessful();

    $settings->update(['jpcert_tags' => ['OpenSSH']], $admin);
    config(['psm.notices.classifier.api_key' => 'test-key']);
    artisan('psm:check-sources')->assertSuccessful();
    artisan('psm:watch-jpcert')->assertSuccessful();
    expect(ChatNotification::count())->toBe(0);
    expect(SecurityNewsArticle::where('title', 'Published while paused')->firstOrFail()->is_backfill)->toBeTrue();

    travelTo(now()->addMinutes(30));
    artisan('psm:watch-jpcert')->assertSuccessful();
    $article = SecurityNewsArticle::where('title', 'Urgent update to older advisory')->firstOrFail();
    expect($article->published_on->toDateString())->toBe('2026-10-01');
    expect($article->notification_queued_at)->not->toBeNull();
    expect(ChatNotification::sole()->kind)->toBe(ChatNotificationKind::JpcertNews);
});
