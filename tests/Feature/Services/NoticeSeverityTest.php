<?php

use App\Enums\Severity;
use App\Services\Notices\NoticeEntry;

it('reads the severity a vendor rates a vulnerability in words', function (string $title, string $text, Severity $severity) {
    expect((new NoticeEntry('demo', $title, $text))->severity())->toBe($severity);
})->with([
    'critical in the title' => ['Upcoming Critical PHP Security Release', 'The PHP security team would like to give advance notice.', Severity::Critical],
    'critical vulnerabilities' => ['PHP 8.6.0 RC4 available for testing', 'This version also contains the fixes for two critical vulnerabilities.', Severity::Critical],
    'critical remote code execution' => ['Security advisory: Critical remote code execution vulnerabilities, update now', '', Severity::Critical],
    'high-severity flaw' => ['Node.js security release', 'It fixes a high-severity flaw in the HTTP parser.', Severity::High],
    'high severity alone' => ['Node.js security release', 'One issue is rated high-severity.', Severity::High],
    'severity label' => ['CVE-2026-1001', 'Severity: High. Upgrade to 22.20.1.', Severity::High],
    'rated as critical' => ['Ruby security release', 'The issue is rated as critical by the Ruby security team.', Severity::Critical],
    'critical over high' => ['Node.js security release', 'One high severity issue and one critical vulnerability.', Severity::Critical],
]);

it('reads no severity from the level said of anything but a vulnerability', function (string $title, string $text) {
    expect((new NoticeEntry('demo', $title, $text))->severity())->toBe(Severity::Unknown);
})->with([
    'call for papers' => ['Dutch PHP Conference 2023 - Call for Papers', 'We\'re also looking for high-quality, technical and non-technical sessions from speakers who can cover advanced topics. We\'ve had great speakers presenting talks about the PHP ecosystem, frameworks, DevOps, architecture, JavaScript, scaling, testing, performance, security and more.'],
    'high-quality security talks' => ['PHPKonf', 'We look for high-quality security talks.'],
    'mission-critical' => ['PHP in production', 'Run mission-critical security systems on PHP.'],
    'high performance' => ['PHP 8.6 released', 'A high performance release.'],
    'critical parts of the codebase' => ['PHP Core Undergoes Security Audit', 'The audit targeted the most critical parts of the codebase, leading to 27 findings.'],
    'given the severity' => ['PHP security release', 'Given the severity of these vulnerabilities, update now.'],
]);
