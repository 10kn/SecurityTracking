<?php

namespace App\Console\Commands;

use App\Actions\EvaluateRuntimes;
use App\Actions\MatchKnownExploited;
use App\Actions\RefreshKnownExploitedFindings;
use App\Actions\ResolveNoticeCves;
use App\Actions\SendAdminAlert;
use App\Actions\SendFindingAlerts;
use App\Actions\SendPreNoticeAlerts;
use App\Actions\SendSecurityNews;
use App\Actions\SyncKnownExploitedVulnerabilities;
use App\Models\Advisory;
use App\Models\Deployment;
use App\Services\Kev\KnownExploitation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('psm:sync-kev')]
#[Description('Read the CISA Known Exploited Vulnerabilities catalog, match it against pre-notices and package advisories and alert the teams it reaches')]
class SyncKevCatalog extends Command
{
    /**
     * Every step reads the state in the database, not what this run changed, so a step that failed is caught up by the
     * next run: the catalog read last stays in use while CISA cannot be reached. Pre-notices first, so a team told in
     * an incident thread about a CVE gets no separate card for the findings that card stands for; then the runtime
     * findings, rebuilt from the database, and the package findings, without scanning the packages again. Alerts are
     * sent once at the end. A CVE CISA stops listing is reported to the admin for review: what it raised stays.
     */
    public function handle(
        SyncKnownExploitedVulnerabilities $syncCatalog,
        ResolveNoticeCves $resolveNoticeCves,
        MatchKnownExploited $matchKnownExploited,
        SendPreNoticeAlerts $sendPreNoticeAlerts,
        SendSecurityNews $sendSecurityNews,
        EvaluateRuntimes $evaluateRuntimes,
        RefreshKnownExploitedFindings $refreshFindings,
        SendFindingAlerts $sendFindingAlerts,
        SendAdminAlert $sendAdminAlert,
    ): int {
        $failures = 0;

        $steps = [
            'CISA KEV' => fn (): string => 'catalog '.($syncCatalog->handle() ?? '?'),
            'GHSA' => fn (): string => $resolveNoticeCves->handle().' pre-notices with new CVE ids',
            'match' => function () use ($matchKnownExploited, $sendAdminAlert): string {
                $delisted = $matchKnownExploited->handle(
                    Advisory::where(fn ($query) => $query->whereNotNull('aliases')->orWhereNotNull('known_exploited')->orWhereNotNull('resolved_cve_ids'))->orderBy('id')->lazyById(),
                    fn (Advisory $advisory, array $cves) => $sendAdminAlert->handle(
                        "kev-removed:{$advisory->id}:".implode(',', $cves),
                        __('CISA đã gỡ :cves khỏi danh sách KEV', ['cves' => implode(', ', $cves)]),
                        [
                            __('Tin: :title', ['title' => $advisory->title]),
                            __('Mức độ và hạn xử lý đã nâng theo KEV được giữ nguyên. Xem lại finding và incident liên quan.'),
                        ],
                        array_filter([__('Mở nguồn tin') => $advisory->url, __('Mở CISA KEV') => KnownExploitation::url($cves[0]), __('Xem incident') => $advisory->incident_id === null ? null : route('admin.incidents.show', $advisory->incident_id)], filled(...)),
                    ),
                );

                return count($delisted).' advisories delisted';
            },
            'pre-notices' => fn (): string => $sendPreNoticeAlerts->handle().' pre-notice cards queued',
            'security news' => fn (): string => $sendSecurityNews->handle().' security news queued',
            'runtimes' => function () use ($evaluateRuntimes): string {
                $deploymentIds = Deployment::where('is_current', true)->whereHas('runtimes')->orderBy('id')->pluck('id')->all();
                $evaluateRuntimes->syncRuntimeFindings($deploymentIds);

                return count($deploymentIds).' deployments';
            },
            'packages' => fn (): string => $refreshFindings->handle().' package findings',
            'findings' => fn (): string => $sendFindingAlerts->handle().' finding cards queued',
        ];

        foreach ($steps as $step => $run) {
            try {
                $this->components->info("{$step}: {$run()}");
            } catch (Throwable $exception) {
                $failures++;
                report($exception);
                $this->components->error("{$step}: {$exception->getMessage()}");
            }
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
