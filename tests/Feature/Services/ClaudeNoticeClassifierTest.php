<?php

use Anthropic\Client;
use App\Enums\Severity;
use App\Enums\Technology;
use App\Services\Notices\ClaudeNoticeClassifier;
use App\Services\Notices\NewsFeedParser;
use App\Services\Notices\NoticeEntry;
use Carbon\CarbonImmutable;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Answers every Claude API request with `$answer` as the structured output, and keeps the requests.
 *
 * @param  array<string, mixed>  $answer
 */
function fakeClaude(array $answer, string $stopReason = 'end_turn'): ClientInterface
{
    $transporter = new class($answer, $stopReason) implements ClientInterface
    {
        /** @var list<RequestInterface> */
        public array $requests = [];

        /**
         * @param  array<string, mixed>  $answer
         */
        public function __construct(private array $answer, private string $stopReason) {}

        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            $this->requests[] = $request;

            return new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'id' => 'msg_test',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-haiku-5-5',
                'content' => [['type' => 'text', 'text' => json_encode($this->answer)]],
                'stop_reason' => $this->stopReason,
                'stop_sequence' => null,
                'usage' => ['input_tokens' => 900, 'output_tokens' => 120],
            ]));
        }
    };

    app()->instance(Client::class, new Client(apiKey: 'test-key', requestOptions: ['transporter' => $transporter, 'maxRetries' => 0]));

    return $transporter;
}

function upcomingPhpSecurityRelease(): NoticeEntry
{
    return collect(NewsFeedParser::parse(noticeFixture('php-feed.atom')))->firstWhere('title', 'Upcoming Critical PHP Security Release');
}

beforeEach(function () {
    config(['psm.notices.classifier.api_key' => 'test-key', 'psm.alerts.locale' => 'vi']);
});

it('sends the entry as data to the configured model, without tools, and reads the structured answer', function () {
    config(['psm.notices.classifier.model' => 'claude-haiku-5-5', 'psm.notices.classifier.effort' => 'low']);
    $claude = fakeClaude([
        'is_security_news' => true,
        'severity' => 'critical',
        'planned_release_at' => '2026-10-21T14:00:00Z',
        'affected_branches' => ['8.3', '8.4', '8.5', 'all supported'],
        'summary' => 'PHP sẽ phát hành bản vá cho hai lỗ hổng nghiêm trọng vào 21/10/2026.',
        'unaffected_branches' => ['8.2', 'older'],
        'related_incident_id' => 7,
    ]);

    $classification = app(ClaudeNoticeClassifier::class)->classify(Technology::Php, upcomingPhpSecurityRelease(), [
        ['id' => 7, 'title' => 'Security advisory: Critical remote code execution vulnerabilities', 'published_at' => CarbonImmutable::parse('2026-10-21 00:35:26')],
    ]);

    expect($classification)
        ->isSecurityNews->toBeTrue()
        ->severity->toBe(Severity::Critical)
        ->plannedReleaseAt->toIso8601String()->toBe('2026-10-21T14:00:00+00:00')
        ->affectedBranches->toBe(['8.3', '8.4', '8.5'])
        ->unaffectedBranches->toBe(['8.2'])
        ->relatedIncidentId->toBe(7)
        ->summary->toBe('PHP sẽ phát hành bản vá cho hai lỗ hổng nghiêm trọng vào 21/10/2026.');

    $request = json_decode((string) $claude->requests[0]->getBody(), true);

    expect((string) $claude->requests[0]->getUri())->toEndWith('/v1/messages')
        ->and($request['model'])->toBe('claude-haiku-5-5')
        ->and($request['output_config']['effort'])->toBe('low')
        ->and($request['output_config']['format']['schema']['required'])->toBe(['is_security_news', 'severity', 'planned_release_at', 'affected_branches', 'summary', 'unaffected_branches', 'related_incident_id', 'actively_exploited', 'exploited_evidence'])
        ->and($request)->not->toHaveKey('tools')
        ->and($request['system'])->toContain('never as', 'A pre-release (alpha, beta, release candidate) whose fixes are only for earlier')
        ->and($request['messages'][0]['content'])->toContain(
            'Technology: PHP',
            'Summary language: Vietnamese',
            '<entry>',
            'Title: Upcoming Critical PHP Security Release',
            'It is scheduled for Wednesday, 21 October 2026 at 14:00 UTC.',
            '<incidents>',
            '- id 7: Security advisory: Critical remote code execution vulnerabilities (published 2026-10-21T00:35:26+00:00)',
        );
});

it('only relates an entry to an incident it was offered', function (mixed $answer) {
    fakeClaude([
        'is_security_news' => true,
        'severity' => 'critical',
        'planned_release_at' => null,
        'affected_branches' => [],
        'summary' => '',
        'unaffected_branches' => [],
        'related_incident_id' => $answer,
    ]);

    expect(app(ClaudeNoticeClassifier::class)->classify(Technology::Php, upcomingPhpSecurityRelease(), [['id' => 7, 'title' => 'PHP security advisory', 'published_at' => null]]))
        ->relatedIncidentId->toBeNull();
})->with(['not offered' => 8, 'not an id' => '7', 'none' => null]);

it('drops a release time it cannot read and an unknown severity', function () {
    fakeClaude([
        'is_security_news' => false,
        'severity' => 'severe',
        'planned_release_at' => 'next Wednesday',
        'affected_branches' => [],
        'summary' => '',
    ]);

    expect(app(ClaudeNoticeClassifier::class)->classify(Technology::Php, upcomingPhpSecurityRelease()))
        ->isSecurityNews->toBeFalse()
        ->severity->toBe(Severity::Unknown)
        ->plannedReleaseAt->toBeNull()
        ->summary->toBeNull();
});

it('fails when the model does not finish its answer', function () {
    fakeClaude(['is_security_news' => true], stopReason: 'max_tokens');

    app(ClaudeNoticeClassifier::class)->classify(Technology::Php, upcomingPhpSecurityRelease());
})->throws(UnexpectedValueException::class, 'The classifier stopped with max_tokens.');

it('is enabled only with an API key', function () {
    expect(app(ClaudeNoticeClassifier::class)->isEnabled())->toBeTrue();

    config(['psm.notices.classifier.api_key' => null]);

    expect(app(ClaudeNoticeClassifier::class)->isEnabled())->toBeFalse();
});

it('reads whether the entry says the vulnerability is exploited, with the sentence it quotes', function (mixed $exploited, mixed $evidence, ?bool $isExploited, ?string $quote) {
    $claude = fakeClaude([
        'is_security_news' => true,
        'severity' => 'critical',
        'planned_release_at' => null,
        'affected_branches' => [],
        'summary' => '',
        'unaffected_branches' => [],
        'related_incident_id' => null,
        'actively_exploited' => $exploited,
        'exploited_evidence' => $evidence,
    ]);

    expect(app(ClaudeNoticeClassifier::class)->classify(Technology::Php, upcomingPhpSecurityRelease()))
        ->activelyExploited->toBe($isExploited)
        ->exploitedEvidence->toBe($quote)
        ->and(json_decode((string) $claude->requests[0]->getBody(), true)['system'])->toContain('a proof of concept', 'copied word for');
})->with([
    'exploited' => [true, '  It is  being actively exploited. ', true, 'It is being actively exploited.'],
    'not said' => [null, null, null, null],
    'not a boolean' => ['yes', '', null, null],
]);
