<?php

use App\Actions\AssignNoticeToIncident;
use App\Enums\IncidentScope;
use App\Enums\RuntimeStatus;
use App\Enums\Severity;
use App\Enums\Technology;
use App\Models\Advisory;
use App\Models\Incident;
use App\Models\Runtime;
use App\Models\RuntimeBranch;

it('confirms a branch only when the vendor names it, rules one out only when the vendor says so, and never rules out an end-of-life branch', function (?string $branch, string $version, RuntimeStatus $status, IncidentScope $scope) {
    $incident = Incident::factory()->make(['affected_branches' => ['8.3', '8.4'], 'unaffected_branches' => ['8.5', '8.1']]);
    $runtime = Runtime::factory()->make(['technology' => Technology::Php, 'raw_version' => $version, 'version' => $version, 'branch' => $branch, 'status' => $status]);

    expect($incident->scopeFor($runtime))->toBe($scope);
})->with([
    'named as affected' => ['8.3', '8.3.35', RuntimeStatus::OutdatedSecurity, IncidentScope::Confirmed],
    'named as not affected' => ['8.5', '8.5.11', RuntimeStatus::Ok, IncidentScope::NotAffected],
    'not named' => ['8.2', '8.2.30', RuntimeStatus::Ok, IncidentScope::Unknown],
    'end of life, named as not affected' => ['8.1', '8.1.31', RuntimeStatus::Eol, IncidentScope::Unknown],
    'branch not known, version named' => [null, '8.4.26', RuntimeStatus::Unknown, IncidentScope::Confirmed],
]);

it('never reads a narrower entry as naming a wider branch', function (array $affected, array $unaffected, IncidentScope $scope) {
    $incident = Incident::factory()->make(['technology' => Technology::Nodejs, 'affected_branches' => $affected, 'unaffected_branches' => $unaffected]);
    $runtime = Runtime::factory()->make(['technology' => Technology::Nodejs, 'raw_version' => '22.20.0', 'version' => '22.20.0', 'branch' => '22', 'status' => RuntimeStatus::Ok]);

    expect($incident->scopeFor($runtime))->toBe($scope);
})->with([
    'another minor ruled out' => [[], ['22.1'], IncidentScope::Unknown],
    'its minor named' => [['22.20'], [], IncidentScope::Confirmed],
    'its major named' => [['22'], [], IncidentScope::Confirmed],
]);

it('refreshes the incident a pre-notice is in now, even from a model loaded before an admin moved it', function () {
    $stale = Advisory::factory()->preNotice(Technology::Php)->create(['severity' => Severity::High, 'aliases' => ['CVE-2026-1001']]);
    app(AssignNoticeToIncident::class)->handle($stale);
    $stale->refresh();
    $other = Incident::factory()->create(['severity' => Severity::Low]);
    Advisory::factory()->preNotice(Technology::Php)->create(['severity' => Severity::Low, 'incident_id' => $other->id]);
    Advisory::whereKey($stale->id)->update(['incident_id' => $other->id]);

    $stale->update(['severity' => Severity::Critical]);

    expect($other->refresh()->severity)->toBe(Severity::Critical)
        ->and($stale->refresh()->incident_id)->toBe($other->id);
});

it('rules out a branch first released after the latest pre-notice and the planned release, unless the vendor names it', function (array $incident, RuntimeStatus $status, IncidentScope $scope) {
    RuntimeBranch::factory()->create(['technology' => Technology::Php, 'branch' => '8.5', 'released_at' => '2025-11-20']);
    $runtime = Runtime::factory()->make(['technology' => Technology::Php, 'raw_version' => '8.5.1', 'version' => '8.5.1', 'branch' => '8.5', 'status' => $status]);

    expect(Incident::factory()->make(['technology' => Technology::Php, 'planned_release_at' => null, ...$incident])->scopeFor($runtime))->toBe($scope);
})->with([
    'news about PHP 7.3 from 2019' => [['first_published_at' => '2019-08-29 11:55:58', 'last_published_at' => '2019-08-29 11:55:58', 'affected_branches' => ['7.3']], RuntimeStatus::Ok, IncidentScope::NotAffected],
    'end of life, released after' => [['first_published_at' => '2019-08-29 11:55:58', 'last_published_at' => '2019-08-29 11:55:58'], RuntimeStatus::Eol, IncidentScope::NotAffected],
    'released before the news' => [['first_published_at' => '2026-10-20 20:32:00', 'last_published_at' => '2026-10-21 12:30:00'], RuntimeStatus::Ok, IncidentScope::Unknown],
    'released the day of the news' => [['first_published_at' => '2025-11-20 09:00:00', 'last_published_at' => '2025-11-20 09:00:00'], RuntimeStatus::Ok, IncidentScope::Unknown],
    'a later pre-notice came after it' => [['first_published_at' => '2025-11-01 09:00:00', 'last_published_at' => '2025-12-01 09:00:00'], RuntimeStatus::Ok, IncidentScope::Unknown],
    'the fix was planned after it' => [['first_published_at' => '2025-11-01 09:00:00', 'last_published_at' => '2025-11-01 09:00:00', 'planned_release_at' => '2025-11-25 14:00:00'], RuntimeStatus::Ok, IncidentScope::Unknown],
    'named as affected' => [['first_published_at' => '2019-08-29 11:55:58', 'last_published_at' => '2019-08-29 11:55:58', 'affected_branches' => ['8.5']], RuntimeStatus::Ok, IncidentScope::Confirmed],
    'only the first pre-notice known' => [['first_published_at' => '2019-08-29 11:55:58', 'last_published_at' => null], RuntimeStatus::Ok, IncidentScope::NotAffected],
]);

it('says nothing about a branch the catalog does not date', function () {
    RuntimeBranch::factory()->create(['technology' => Technology::Php, 'branch' => '8.5', 'released_at' => null]);
    $runtime = Runtime::factory()->make(['technology' => Technology::Php, 'raw_version' => '8.5.1', 'version' => '8.5.1', 'branch' => '8.5', 'status' => RuntimeStatus::Ok]);

    expect(Incident::factory()->make(['first_published_at' => '2019-08-29 11:55:58'])->scopeFor($runtime))->toBe(IncidentScope::Unknown);
});

it('says nothing about a branch when the vendor names none', function () {
    $runtime = Runtime::factory()->make(['technology' => Technology::Php, 'raw_version' => '8.3.35', 'branch' => '8.3', 'status' => RuntimeStatus::Ok]);

    expect(Incident::factory()->make()->scopeFor($runtime))->toBe(IncidentScope::Unknown);
});

it('derives itself from its pre-notices that were not dismissed, and moves changed_at only when its severity or branches change', function () {
    $this->travelTo('2026-10-21 12:00:00');
    $first = Advisory::factory()->preNotice(Technology::Php)->create(['title' => 'Upcoming PHP Security Release', 'severity' => Severity::High, 'aliases' => ['CVE-2026-1001'], 'published_at' => '2026-10-20 20:32:00', 'planned_release_at' => '2026-10-21 14:00:00', 'affected_branches' => ['8.4']]);
    app(AssignNoticeToIncident::class)->handle($first);
    $incident = $first->refresh()->incident;
    $changedAt = $incident->changed_at;

    $this->travel(1)->hour();
    $second = Advisory::factory()->preNotice(Technology::Php)->create(['title' => 'PHP security advisory', 'severity' => Severity::High, 'aliases' => ['CVE-2026-1001'], 'published_at' => '2026-10-21 12:30:00', 'affected_branches' => ['8.3']]);
    app(AssignNoticeToIncident::class)->handle($second);
    Advisory::factory()->preNotice(Technology::Php)->create(['title' => 'Not security news', 'severity' => Severity::Critical, 'is_dismissed' => true, 'incident_id' => $incident->id]);

    expect($incident->refresh())
        ->title->toBe('Upcoming PHP Security Release')
        ->severity->toBe(Severity::High)
        ->first_published_at->toDateTimeString()->toBe('2026-10-20 20:32:00')
        ->last_published_at->toDateTimeString()->toBe('2026-10-21 12:30:00')
        ->planned_release_at->toDateTimeString()->toBe('2026-10-21 14:00:00')
        ->affected_branches->toBe(['8.3', '8.4'])
        ->changed_at->toDateTimeString()->toBe(now()->toDateTimeString());

    $changedAt = $incident->changed_at;
    $this->travel(1)->hour();
    $second->update(['localized_summary' => 'PHP sẽ vá lỗ hổng.', 'title' => 'PHP security advisory (updated)']);

    expect($incident->refresh()->changed_at->toDateTimeString())->toBe($changedAt->toDateTimeString());

    $second->update(['severity' => Severity::Critical]);

    expect($incident->refresh())->severity->toBe(Severity::Critical)->changed_at->toDateTimeString()->toBe(now()->toDateTimeString());
});
