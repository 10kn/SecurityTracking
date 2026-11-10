<?php

use App\Actions\AssignNoticeToIncident;
use App\Actions\SyncNoticeFeed;
use App\Enums\IncidentBasis;
use App\Enums\IncidentSuggestionReason;
use App\Enums\IncidentSuggestionStatus;
use App\Enums\Severity;
use App\Enums\Technology;
use App\Models\Advisory;
use App\Models\Incident;
use App\Models\IncidentSuggestion;
use App\Services\Notices\NoticeClassification;
use App\Services\Notices\NoticeEntry;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo('2026-10-21 12:00:00');
});

/**
 * A pre-notice of PHP put in its incident.
 *
 * @param  array<string, mixed>  $attributes
 */
function assignedNotice(array $attributes = []): Advisory
{
    $notice = Advisory::factory()->preNotice(Technology::Php)->create($attributes);
    app(AssignNoticeToIncident::class)->handle($notice);

    return $notice->refresh();
}

it('keeps the php.net pre-announcement and its advisory apart, puts the release announcement in the advisory\'s incident, and suggests they belong together once the release time is known', function () {
    fakeNoticeFeeds();
    $this->travelTo('2026-10-23 10:00:00');
    classifyNoticeFixtures([
        'Upcoming Critical PHP Security Release' => new NoticeClassification(isSecurityNews: true, severity: Severity::Critical, plannedReleaseAt: CarbonImmutable::parse('2026-10-21 14:00:00')),
    ]);

    app(SyncNoticeFeed::class)->handle(Technology::Php);

    $announcement = Advisory::where('title', 'Upcoming Critical PHP Security Release')->sole();
    $advisory = Advisory::where('title', 'like', 'Security advisory%')->sole();
    $release = Advisory::where('title', 'PHP 8.3.36 Released!')->sole();

    expect($announcement->incident_id)->not->toBe($advisory->incident_id)
        ->and($announcement->incident_basis)->toBe(IncidentBasis::Own)
        ->and($advisory->incident_basis)->toBe(IncidentBasis::Own)
        ->and($release)
        ->incident_id->toBe($advisory->incident_id)
        ->incident_basis->toBe(IncidentBasis::SharedId)
        ->and(IncidentSuggestion::where('advisory_id', $advisory->id)->sole())
        ->incident_id->toBe($announcement->incident_id)
        ->reason->toBe(IncidentSuggestionReason::ReleaseWindow)
        ->status->toBe(IncidentSuggestionStatus::Pending)
        ->and($announcement->incident)
        ->title->toBe('Upcoming Critical PHP Security Release')
        ->severity->toBe(Severity::Critical)
        ->planned_release_at->toIso8601String()->toBe('2026-10-21T14:00:00+00:00');
});

it('puts a pre-notice in the incident it shares a CVE or GHSA id with', function (array $aliases, string $evidence) {
    $first = assignedNotice(['aliases' => ['CVE-2026-109618', 'GHSA-w8hw-w9fx-j79w'], 'published_at' => now()->subDay()]);
    $second = assignedNotice(['aliases' => $aliases]);

    expect($second)
        ->incident_id->toBe($first->incident_id)
        ->incident_basis->toBe(IncidentBasis::SharedId)
        ->incident_evidence->toBe([['type' => 'shared_id', 'value' => $evidence, 'advisory_id' => $first->id]])
        ->and(Incident::count())->toBe(1)
        ->and(IncidentSuggestion::count())->toBe(0);
})->with([
    'CVE' => [['CVE-2026-109618'], 'CVE-2026-109618'],
    'GHSA' => [['GHSA-w8hw-w9fx-j79w'], 'GHSA-w8hw-w9fx-j79w'],
]);

it('puts a pre-notice in the incident of a pre-notice it links to, or that links to it', function (string $direction) {
    $address = 'https://www.php.net/archive/2026.php#2026-10-21-5';
    $first = assignedNotice($direction === 'to' ? ['url' => $address, 'external_id' => $address] : ['links' => ['https://nodejs.org/en/blog/vulnerability/october-2026']]);
    $second = assignedNotice($direction === 'to' ? ['links' => ['https://www.php.net/archive/2026.php#2026-10-21-5']] : ['url' => 'http://NodeJS.org/en/blog/vulnerability/october-2026']);

    expect($second)
        ->incident_id->toBe($first->incident_id)
        ->incident_basis->toBe(IncidentBasis::DirectLink);
})->with(['to' => 'to', 'from' => 'from']);

it('does not join a page that only shares a link with the pre-notice, or a fragment of another page', function () {
    $first = assignedNotice(['url' => 'https://www.php.net/archive/2026.php#2026-10-20-1', 'links' => ['https://www.php.net/downloads.php']]);
    $second = assignedNotice(['url' => 'https://www.php.net/archive/2026.php#2026-10-21-5', 'links' => ['https://www.php.net/downloads.php', 'https://www.php.net/archive/2026.php#2026-10-21-2']]);

    expect($second->incident_id)->not->toBe($first->incident_id);
});

it('never joins an incident of another technology', function () {
    $node = Advisory::factory()->preNotice(Technology::Nodejs)->create(['aliases' => ['CVE-2026-109618']]);
    app(AssignNoticeToIncident::class)->handle($node);

    expect(assignedNotice(['aliases' => ['CVE-2026-109618']])->incident_id)->not->toBe($node->refresh()->incident_id);
});

it('starts its own incident and suggests each match when a new pre-notice matches several incidents', function () {
    $first = assignedNotice(['aliases' => ['CVE-2026-1001']]);
    $second = assignedNotice(['aliases' => ['CVE-2026-1002']]);
    $third = assignedNotice(['aliases' => ['CVE-2026-1001', 'CVE-2026-1002']]);

    expect($third->incident_basis)->toBe(IncidentBasis::Own)
        ->and(Incident::count())->toBe(3)
        ->and(IncidentSuggestion::where('advisory_id', $third->id)->pluck('incident_id')->all())
        ->toEqualCanonicalizing([$first->incident_id, $second->incident_id]);
});

it('only suggests when a vendor edit ties a pre-notice to another incident, and not again once rejected', function () {
    $first = assignedNotice(['aliases' => ['CVE-2026-1001']]);
    $second = assignedNotice(['aliases' => []]);

    $second->update(['aliases' => ['CVE-2026-1001']]);
    app(AssignNoticeToIncident::class)->handle($second);

    $suggestion = IncidentSuggestion::sole();
    expect($second->refresh()->incident_id)->not->toBe($first->incident_id)
        ->and($suggestion)->reason->toBe(IncidentSuggestionReason::SharedId)->incident_id->toBe($first->incident_id);

    $suggestion->update(['status' => IncidentSuggestionStatus::Rejected]);
    app(AssignNoticeToIncident::class)->handle($second);

    expect(IncidentSuggestion::sole()->status)->toBe(IncidentSuggestionStatus::Rejected);
});

it('leaves pre-notices an admin placed alone', function () {
    assignedNotice(['aliases' => ['CVE-2026-1001'], 'planned_release_at' => now()]);
    $placed = assignedNotice(['aliases' => [], 'published_at' => now()->subDays(5)]);
    $placed->update(['incident_basis' => IncidentBasis::Manual, 'aliases' => ['CVE-2026-1001']]);

    app(AssignNoticeToIncident::class)->handle($placed);

    expect(IncidentSuggestion::count())->toBe(0);
});

it('suggests the incident whose planned release a pre-notice was published around, only within the window', function (string $published, int $suggestions) {
    $announcement = assignedNotice(['published_at' => '2026-10-20 20:32:00', 'planned_release_at' => '2026-10-21 14:00:00']);
    $this->travelTo('2026-10-25 12:00:00');

    assignedNotice(['published_at' => $published]);

    expect(IncidentSuggestion::where('incident_id', $announcement->incident_id)->where('reason', IncidentSuggestionReason::ReleaseWindow)->count())->toBe($suggestions);
})->with([
    'a day before' => ['2026-10-20 14:00:00', 1],
    'right after' => ['2026-10-21 00:35:26', 1],
    'two days after' => ['2026-10-23 14:00:00', 1],
    'three days after' => ['2026-10-24 14:00:01', 0],
]);

it('suggests the incident the classifier relates a pre-notice to, if it is a current incident of the technology', function () {
    fakeNoticeFeeds();
    $this->travelTo('2026-10-23 10:00:00');
    $earlier = assignedNotice(['title' => 'PHP security notice', 'published_at' => '2026-10-19 10:00:00']);
    $offered = new ArrayObject;
    classifyNoticesWith(function (NoticeEntry $entry, array $incidents) use ($earlier, $offered): NoticeClassification {
        $offered[$entry->title] = array_column($incidents, 'title', 'id');

        return new NoticeClassification(isSecurityNews: true, severity: Severity::Critical, relatedIncidentId: $entry->title === 'Upcoming Critical PHP Security Release' ? $earlier->incident_id : null);
    });

    app(SyncNoticeFeed::class)->handle(Technology::Php);

    $announcement = Advisory::where('title', 'Upcoming Critical PHP Security Release')->sole();
    expect($offered['Upcoming Critical PHP Security Release'])->toHaveKey($earlier->incident_id, 'PHP security notice')
        ->and(IncidentSuggestion::where('reason', IncidentSuggestionReason::Classifier)->sole())
        ->advisory_id->toBe($announcement->id)
        ->incident_id->toBe($earlier->incident_id)
        ->and($announcement->incident_id)->not->toBe($earlier->incident_id);
});

it('gives pre-notices stored before incidents existed their incident, oldest first', function () {
    $older = Advisory::factory()->preNotice(Technology::Php)->create(['aliases' => ['CVE-2026-1001'], 'published_at' => now()->subDays(2)]);
    $newer = Advisory::factory()->preNotice(Technology::Php)->create(['aliases' => ['CVE-2026-1001'], 'published_at' => now()->subDay()]);

    app(AssignNoticeToIncident::class)->assignUnassigned();

    expect($older->refresh()->incident_basis)->toBe(IncidentBasis::Own)
        ->and($newer->refresh())->incident_id->toBe($older->incident_id)->incident_basis->toBe(IncidentBasis::SharedId);
});

it('finds a pre-notice by its address however the feed wrote it', function (string $stored, string $linked) {
    $first = assignedNotice(['url' => $stored, 'external_id' => 'notice-1']);

    expect(assignedNotice(['links' => [$linked]])->incident_id)->toBe($first->incident_id);
})->with([
    'default port and case' => ['http://Vendor.test:80/notice', 'https://vendor.test/notice'],
    'empty path' => ['https://Vendor.test', 'https://vendor.test/'],
]);

it('offers the classifier the incidents with a pre-notice within the relevant days, the latest publication first', function () {
    config(['psm.notices.relevant_days' => 14]);
    $followedUp = Incident::factory()->create(['technology' => Technology::Php, 'title' => 'Followed up', 'first_published_at' => now()->subDays(30), 'last_published_at' => now()->subDay()]);
    $recent = Incident::factory()->create(['technology' => Technology::Php, 'title' => 'Recent', 'first_published_at' => now()->subDays(3), 'last_published_at' => null]);
    Incident::factory()->create(['technology' => Technology::Php, 'title' => 'Quiet', 'first_published_at' => now()->subDays(30), 'last_published_at' => now()->subDays(20)]);
    Incident::factory()->create(['technology' => Technology::Nodejs, 'title' => 'Other technology', 'first_published_at' => now()->subDay()]);

    expect(collect(app(AssignNoticeToIncident::class)->recentIncidents(Technology::Php, null))->pluck('title')->all())->toBe(['Followed up', 'Recent'])
        ->and(collect(app(AssignNoticeToIncident::class)->recentIncidents(Technology::Php, $followedUp->id))->pluck('id')->all())->toBe([$recent->id]);
});
