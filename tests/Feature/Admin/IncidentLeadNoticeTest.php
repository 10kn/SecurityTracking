<?php

use App\Enums\Severity;
use App\Enums\SeverityBasis;
use App\Enums\Technology;
use App\Models\Advisory;
use App\Models\Incident;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\get;

beforeEach(function () {
    $this->travelTo('2026-10-20 21:00:00');
    $this->incident = Incident::factory()->create(['technology' => Technology::Php, 'title' => 'PHP 8.6.0 RC4 available for testing', 'first_published_at' => now()]);
    $this->notice = Advisory::factory()->preNotice(Technology::Php)->create([
        'title' => 'PHP 8.6.0 RC4 available for testing',
        'severity' => Severity::Critical,
        'severity_basis' => SeverityBasis::Classifier,
        'summary' => 'The PHP team announces the fourth release candidate of PHP 8.6.0.',
        'localized_summary' => 'Bản RC4 sửa một lỗ hổng nghiêm trọng trong ext/phar.',
        'classified_by' => 'claude-haiku-5-5',
        'classified_severity' => Severity::Critical,
        'url' => 'https://www.php.net/archive/2026.php#2026-10-20-2',
        'published_at' => now(),
        'incident_id' => $this->incident->id,
    ]);
});

it('explains the lead pre-notice with its AI summary, what gave its severity, its source and the classifier', function () {
    actingAsAdmin();

    get("/admin/incidents/{$this->incident->id}")->assertInertia(fn (Assert $page) => $page
        ->where('incident.lead_notice', [
            'summary' => ['text' => 'Bản RC4 sửa một lỗ hổng nghiêm trọng trong ext/phar.', 'is_ai' => true],
            'severity_basis' => 'Mức do AI đánh giá',
            'url' => 'https://www.php.net/archive/2026.php#2026-10-20-2',
            'classifier' => ['label' => 'AI đã phân loại tin này', 'tone' => 'neutral'],
        ])
        ->where('notices.0.severity_basis', 'Mức do AI đánh giá'));
});

it('falls back to the summary of the source while the classifier has not run', function () {
    actingAsAdmin();
    $this->notice->update(['localized_summary' => null, 'classified_by' => null, 'classified_severity' => null, 'severity_basis' => SeverityBasis::Vendor]);

    get("/admin/incidents/{$this->incident->id}")->assertInertia(fn (Assert $page) => $page
        ->where('incident.lead_notice.summary', ['text' => 'The PHP team announces the fourth release candidate of PHP 8.6.0.', 'is_ai' => false])
        ->where('incident.lead_notice.severity_basis', 'Mức theo trang chính thức PHP')
        ->where('incident.lead_notice.classifier', ['label' => 'AI chưa phân loại tin này', 'tone' => 'unknown']));
});

it('says the classifier failed, and keeps an earlier result only when there was one', function () {
    actingAsAdmin();
    $this->notice->update(['classification_failed_at' => now()]);

    get("/admin/incidents/{$this->incident->id}")->assertInertia(fn (Assert $page) => $page
        ->where('incident.lead_notice.classifier', ['label' => 'AI phân loại lại bị lỗi, đang giữ kết quả lần trước', 'tone' => 'danger']));

    $this->notice->update(['classified_by' => null, 'classified_severity' => null, 'localized_summary' => null]);

    get("/admin/incidents/{$this->incident->id}")->assertInertia(fn (Assert $page) => $page
        ->where('incident.lead_notice.classifier', ['label' => 'AI phân loại tin này bị lỗi', 'tone' => 'danger']));
});

it('says nothing it does not know: no summary and no severity basis', function () {
    actingAsAdmin();
    $this->notice->update(['localized_summary' => '', 'summary' => null, 'severity_basis' => null]);

    get("/admin/incidents/{$this->incident->id}")->assertInertia(fn (Assert $page) => $page
        ->where('incident.lead_notice.summary', null)
        ->where('incident.lead_notice.severity_basis', null));
});

it('explains the most severe pre-notice that is not dismissed', function () {
    actingAsAdmin();
    Advisory::factory()->preNotice(Technology::Php)->create(['severity' => Severity::Low, 'localized_summary' => 'Tin phụ.', 'published_at' => now()->subHour(), 'incident_id' => $this->incident->id]);

    get("/admin/incidents/{$this->incident->id}")->assertInertia(fn (Assert $page) => $page
        ->where('incident.lead_notice.summary.text', 'Bản RC4 sửa một lỗ hổng nghiêm trọng trong ext/phar.'));
});

it('marks an incident whose every pre-notice is not security news', function () {
    actingAsAdmin();

    get("/admin/incidents/{$this->incident->id}")->assertInertia(fn (Assert $page) => $page->where('incident.is_dismissed', false));

    $this->notice->update(['is_dismissed' => true, 'severity' => Severity::Unknown, 'severity_basis' => null]);

    get("/admin/incidents/{$this->incident->id}")->assertInertia(fn (Assert $page) => $page->where('incident.is_dismissed', true));
});
