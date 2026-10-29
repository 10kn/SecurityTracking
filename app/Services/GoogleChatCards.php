<?php

namespace App\Services;

use App\Enums\FindingState;
use App\Enums\Severity;
use App\Models\Advisory;
use App\Models\Finding;
use App\Models\Incident;
use App\Models\Runtime;
use App\Models\Service;
use App\Models\Team;
use App\Services\Kev\KnownExploitation;
use App\Support\LocalTime;
use App\Support\Version;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Builds Google Chat messages (cardsV2) in the application's default language. Paragraphs and decorated text accept
 * a few HTML tags, so database text placed there is escaped; headers and labels are plain text.
 */
class GoogleChatCards
{
    /**
     * One card for one issue at one team, listing every affected service. Services added since the previous card are
     * marked new. When CISA KEV lists a CVE id of the issue, the card says so first, with the severity its source gave.
     *
     * @param  Collection<int, Finding>  $findings  Open findings of the issue, with `deployment.service.project`.
     * @param  list<int>  $newFindingIds
     * @return array<string, mixed>
     */
    public function findingAlert(Team $team, Collection $findings, array $newFindingIds): array
    {
        /** @var Finding $first */
        $first = $findings->sortByDesc(fn (Finding $finding): int => $finding->severity->rank())->first();
        $severity = $first->severity->getLabel();

        // On the first card of an issue every service is new, so "new" only marks services added to a known issue.
        $newFindingIds = count($newFindingIds) < $findings->count() ? $newFindingIds : [];

        $widgets = $findings
            ->sortBy(fn (Finding $finding): string => $finding->deployment->service->name)
            ->take($this->maxListedItems())
            ->map(fn (Finding $finding): array => $this->serviceWidget(
                $finding->deployment->service,
                $this->findingDetail($finding),
                in_array($finding->id, $newFindingIds, true),
            ))
            ->values()
            ->all();

        $sections = [['header' => __(':count service bị ảnh hưởng', ['count' => $findings->count()]), 'widgets' => [...$widgets, ...$this->moreWidget($findings->count())]]];

        if (filled($first->context['advisory_title'] ?? null)) {
            array_unshift($sections, ['widgets' => [['textParagraph' => ['text' => e($first->context['advisory_title'])]]]]);
        }

        $exploited = $findings->filter(fn (Finding $finding): bool => filled($finding->context['known_exploited'] ?? null))->values();

        if ($exploited->isNotEmpty()) {
            array_unshift($sections, ['widgets' => $this->knownExploitedWidgets($exploited)]);
        }

        $links = array_filter([
            __('Mở nguồn tin') => $first->context['source_url'] ?? null,
            __('Mở CISA KEV') => $exploited->isEmpty() ? null : KnownExploitation::url($exploited->first()->context['known_exploited'][0]['cve']),
            __('Đánh dấu đã đọc') => $this->findingsUrl(['team' => $team->id, 'ids' => implode(',', $findings->sortBy('id')->modelKeys())]),
        ], filled(...));

        $sections[] = ['widgets' => [
            ['textParagraph' => ['text' => e(__('Nhắc lại trong bản tổng hợp mỗi ngày cho tới khi admin đánh dấu đã đọc. Hạn xử lý: :date.', ['date' => $this->date($first->sla_due_at)]))]],
            $this->buttons($links),
        ]];

        return $this->card("finding-{$first->id}", "[{$severity}] {$first->title}", $team->name, $sections);
    }

    /**
     * What CISA KEV says about the findings of a card: every CVE id it lists, when it added them and the date it asks
     * them fixed by, the fixes of the branches that address them for runtimes, and the severity the sources gave.
     *
     * @param  Collection<int, Finding>  $findings  The card's findings with KEV evidence.
     * @return list<array<string, mixed>>
     */
    private function knownExploitedWidgets(Collection $findings): array
    {
        $evidence = $findings->flatMap(fn (Finding $finding): array => $finding->context['known_exploited'])->unique('cve')->sortBy('cve')->values()->all();
        $fixes = $findings
            ->flatMap(function (Finding $finding): array {
                $cves = KnownExploitation::cves($finding->context['known_exploited']);
                $relatedPatches = is_array($finding->context['related_patches'] ?? null) ? $finding->context['related_patches'] : [];

                return collect($relatedPatches)
                    ->filter(fn (array $patch): bool => array_intersect($patch['cves'] ?? [], $cves) !== [])
                    ->map(fn (array $patch): string => __('Bản vá :version của nhánh :branch xử lý :cves.', ['version' => $patch['fixed_version'], 'branch' => $finding->context['branch'] ?? '', 'cves' => implode(', ', array_intersect($patch['cves'], $cves))]))
                    ->all();
            })
            ->unique()
            ->sort()
            ->values();
        $sources = $findings
            ->map(function (Finding $finding): ?string {
                $source = Severity::tryFrom((string) ($finding->context['source_severity'] ?? ''));
                $sourceCvss = $finding->context['source_cvss'] ?? null;

                return $source === null || $source === $finding->severity ? null : $source->getLabel().($sourceCvss === null ? '' : " · CVSS {$sourceCvss}");
            })
            ->filter()
            ->unique()
            ->values();

        return [
            ['textParagraph' => ['text' => '<b>'.e(KnownExploitation::describe($evidence)).'</b>']],
            ...$fixes->map(fn (string $fix): array => ['textParagraph' => ['text' => e($fix)]])->all(),
            ['textParagraph' => ['text' => e(implode(' ', array_filter([
                __('Không có nghĩa service của team đã bị tấn công: ưu tiên nâng cấp hoặc giảm thiểu ngay.'),
                $sources->isEmpty() ? null : __('Mức theo nguồn: :severity.', ['severity' => $sources->join(', ')]),
            ])))]],
        ];
    }

    /**
     * "Cảnh báo chuẩn bị": a vendor's security news for one team, one card per incident, led by its most severe
     * pre-notice and listing the team's services that run the technology with whether the vendor said their branch is
     * affected.
     *
     * @param  Collection<int, Advisory>  $others  The incident's other pre-notices that count now.
     * @param  Collection<int, Runtime>  $runtimes  The team's runtimes of the technology, with `deployment.service.project`.
     * @param  Severity|null  $previousSeverity  The severity of the card the team got before, when it rose since.
     * @param  list<string>  $changes  What changed since the team was told, when this card opens a thread for an update.
     * @return array<string, mixed>
     */
    public function preNotice(Team $team, Incident $incident, Advisory $advisory, Collection $others, Collection $runtimes, ?Severity $previousSeverity = null, array $changes = []): array
    {
        $technology = $advisory->technology;

        $widgets = $this->incidentWidgets($incident, $advisory, $previousSeverity, __('Hãng vừa đăng tin bảo mật về :technology. Kiểm tra và chuẩn bị nâng cấp các service dưới đây.', ['technology' => $technology->getLabel()]), $changes);

        $services = $runtimes
            ->sortBy(fn (Runtime $runtime): string => $runtime->deployment->service->name)
            ->take($this->maxListedItems())
            ->map(fn (Runtime $runtime): array => $this->serviceWidget($runtime->deployment->service, $runtime->displayName().' · '.__('Phạm vi: :scope', ['scope' => $incident->scopeFor($runtime)->getLabel()])))
            ->values()
            ->all();

        $sections = [
            ['widgets' => $widgets],
            ['header' => __(':count service đang chạy :technology', ['count' => $runtimes->count(), 'technology' => $technology->getLabel()]), 'widgets' => [...$services, ...$this->moreWidget($runtimes->count())]],
            ...$this->otherNoticesSection($others),
        ];

        if (filled($advisory->url)) {
            $sections[] = ['widgets' => [$this->buttons([__('Mở nguồn tin') => $advisory->url])]];
        }

        return $this->card("incident-{$incident->id}", $this->severityTitle($advisory->severity, $advisory->title), __('Cảnh báo chuẩn bị · :team', ['team' => $team->name]), $sections);
    }

    /**
     * "Đã có bản vá": the vendor confirmed the fixed version of branches the team runs. It also warns of the incident
     * itself, for a team that had not heard of it, and lists the services to upgrade with their target (those below
     * the fixed version, or whose version is not known), counts those already at it or later, then lists those of
     * branches still waiting for a fix. A branch whose fix an earlier card announced counts as having one. Having a fix does not make a service safe until it is upgraded.
     *
     * @param  Collection<int, Advisory>  $others  The incident's other pre-notices that count now.
     * @param  Collection<int, Runtime>  $runtimes  The team's runtimes of the technology, with `deployment.service.project`.
     * @param  array<string, string>  $patches  Branch => fixed version, for the branches the card is about.
     * @param  array<string, string>  $knownPatches  Branch => fixed version, for every branch the incident has a fix for,
     *                                               including those of earlier cards: their services are not waiting.
     * @param  list<string>  $changes  What else changed since the team was told, when this card opens a thread for an update.
     * @return array<string, mixed>
     */
    public function patchAvailable(Team $team, Incident $incident, Advisory $advisory, Collection $others, Collection $runtimes, array $patches, array $knownPatches, ?Severity $previousSeverity, ?CarbonInterface $deadline, array $changes = []): array
    {
        $technology = $advisory->technology;
        $severity = $advisory->severity;
        $versions = implode(', ', array_values($patches));

        $widgets = [
            ['textParagraph' => ['text' => '<b>'.e($advisory->title).'</b>']],
            ...$this->incidentWidgets($incident, $advisory, $previousSeverity, __('Hãng đã xác nhận bản vá :versions. Có bản vá không có nghĩa service đã an toàn: chỉ an toàn sau khi nâng cấp.', ['versions' => $versions]), $changes),
        ];
        $widgets[] = ['textParagraph' => ['text' => '<b>'.e(__('Hạn xử lý: :date.', ['date' => $this->date($deadline)])).'</b>']];

        $targets = array_replace($knownPatches, $patches);
        $waiting = $runtimes
            ->sortBy(fn (Runtime $runtime): string => $runtime->deployment->service->name)
            ->reject(fn (Runtime $runtime): bool => isset($targets[$runtime->branch]));

        $sections = [
            ['widgets' => $widgets],
            $this->upgradeSection($runtimes, $targets),
        ];

        if ($waiting->isNotEmpty()) {
            $sections[] = [
                'header' => __(':count service ở nhánh chưa có bản vá', ['count' => $waiting->count()]),
                'widgets' => [
                    ...$waiting
                        ->take($this->maxListedItems())
                        ->map(fn (Runtime $runtime): array => $this->serviceWidget($runtime->deployment->service, $runtime->displayName().' · '.__('Phạm vi: :scope', ['scope' => $incident->scopeFor($runtime)->getLabel()])))
                        ->values()
                        ->all(),
                    ...$this->moreWidget($waiting->count()),
                ],
            ];
        }

        $sections = [...$sections, ...$this->otherNoticesSection($others)];
        $sections[] = ['widgets' => [$this->buttons(array_filter([
            __('Mở nguồn tin') => $advisory->url,
            __('Xem finding') => $this->findingsUrl(['team' => $team->id]),
        ], filled(...)))]];

        $title = $this->severityTitle($severity, __('Đã có bản vá, nâng lên :versions', ['versions' => $versions]));

        return $this->card("incident-{$incident->id}", $title, __(':technology · :team', ['technology' => $technology->getLabel(), 'team' => $team->name]), $sections);
    }

    /**
     * A reply in the incident's thread when something the team must act on changed: what changed, the team's services
     * of the technology (with their target when a fix is due), what to do and the source. A fix keeps its target
     * versions and deadline. An admin's update leads with the admin's words.
     *
     * @param  list<string>  $changes  What changed, most important first.
     * @param  Collection<int, Runtime>  $runtimes  The team's runtimes of the technology, with `deployment.service.project`.
     * @param  array<string, string>  $patches  Branch => fixed version, for the fixes this reply announces.
     * @param  array<string, string>  $knownPatches  Branch => fixed version, for every fix of the incident.
     * @return array<string, mixed>
     */
    public function incidentUpdate(Team $team, Incident $incident, Advisory $advisory, array $changes, Collection $runtimes, string $action, array $patches = [], array $knownPatches = [], ?CarbonInterface $deadline = null, ?string $adminMessage = null): array
    {
        $technology = $advisory->technology;

        $widgets = [
            ['textParagraph' => ['text' => '<b>'.e($incident->title).'</b>']],
            ...array_map(fn (string $change): array => ['textParagraph' => ['text' => '<b>'.e($change).'</b>']], $changes),
            ...(blank($adminMessage) ? [] : [['textParagraph' => ['text' => e($adminMessage)]]]),
            ['textParagraph' => ['text' => e($action)]],
            ...($patches === [] ? [] : [['textParagraph' => ['text' => '<b>'.e(__('Hạn xử lý: :date.', ['date' => $this->date($deadline)])).'</b>']]]),
        ];

        $services = $patches !== []
            ? $this->upgradeSection($runtimes, array_replace($knownPatches, $patches))
            : [
                'header' => __(':count service đang chạy :technology', ['count' => $runtimes->count(), 'technology' => $technology->getLabel()]),
                'widgets' => [
                    ...$runtimes
                        ->sortBy(fn (Runtime $runtime): string => $runtime->deployment->service->name)
                        ->take($this->maxListedItems())
                        ->map(fn (Runtime $runtime): array => $this->serviceWidget($runtime->deployment->service, $runtime->displayName().' · '.__('Phạm vi: :scope', ['scope' => $incident->scopeFor($runtime)->getLabel()])))
                        ->values()
                        ->all(),
                    ...$this->moreWidget($runtimes->count()),
                ],
            ];

        $sections = [['widgets' => $widgets]];

        if ($runtimes->isNotEmpty()) {
            $sections[] = $services;
        }

        $links = array_filter([
            __('Mở nguồn tin') => $advisory->url,
            __('Xem finding') => $patches === [] ? null : $this->findingsUrl(['team' => $team->id]),
        ], filled(...));

        if ($links !== []) {
            $sections[] = ['widgets' => [$this->buttons($links)]];
        }

        $title = $patches === []
            ? $this->severityTitle($advisory->severity, $advisory->title)
            : $this->severityTitle($advisory->severity, __('Đã có bản vá, nâng lên :versions', ['versions' => implode(', ', array_values($patches))]));

        return $this->card("incident-{$incident->id}", $title, __('Cập nhật · :team', ['team' => $team->name]), $sections);
    }

    /**
     * The services to upgrade with their target (those below the fixed version of their branch, or whose version is
     * not known), and how many are already at it or later.
     *
     * @param  Collection<int, Runtime>  $runtimes
     * @param  array<string, string>  $targets  Branch => fixed version.
     * @return array<string, mixed>
     */
    private function upgradeSection(Collection $runtimes, array $targets): array
    {
        [$toUpgrade, $atFix] = $runtimes
            ->sortBy(fn (Runtime $runtime): string => $runtime->deployment->service->name)
            ->filter(fn (Runtime $runtime): bool => isset($targets[$runtime->branch]))
            ->partition(fn (Runtime $runtime): bool => $runtime->version === null || Version::isNewer($targets[$runtime->branch], $runtime->version));

        return [
            'header' => __(':count service cần nâng cấp', ['count' => $toUpgrade->count()]),
            'widgets' => [
                ...$toUpgrade
                    ->take($this->maxListedItems())
                    ->map(fn (Runtime $runtime): array => $this->serviceWidget($runtime->deployment->service, __(':runtime → :version', ['runtime' => $runtime->displayName(), 'version' => $targets[$runtime->branch]])))
                    ->values()
                    ->all(),
                ...$this->moreWidget($toUpgrade->count()),
                ...($atFix->isEmpty() ? [] : [['textParagraph' => ['text' => e(__(':count service đã ở bản vá hoặc mới hơn.', ['count' => $atFix->count()]))]]]),
            ],
        ];
    }

    /**
     * What a card says about an incident: a rise in severity, the opening line, why the severity is unknown, the
     * summary, the planned release and affected branches, and the facts of the leading pre-notice.
     *
     * @param  list<string>  $changes  Shown in bold first, e.g. that the incident is exploited.
     * @return list<array<string, mixed>>
     */
    private function incidentWidgets(Incident $incident, Advisory $advisory, ?Severity $previousSeverity, string $opening, array $changes = []): array
    {
        $plannedReleaseAt = $incident->planned_release_at ?? $advisory->planned_release_at;

        $facts = array_filter([
            __('Nguồn: :source', ['source' => $advisory->technology->releaseSourceLabel()]),
            $advisory->published_at === null ? null : __('Đăng lúc :time', ['time' => LocalTime::format($advisory->published_at)]),
            $advisory->cvss_score === null ? null : "CVSS {$advisory->cvss_score}",
            $advisory->severity_basis?->getLabel(),
        ]);

        $plan = array_filter([
            $plannedReleaseAt === null ? null : __('Dự kiến phát hành :time', ['time' => LocalTime::format($plannedReleaseAt)]),
            blank($incident->affected_branches) ? null : __('Nhánh bị ảnh hưởng: :branches', ['branches' => implode(', ', $incident->affected_branches)]),
        ]);
        $summary = $advisory->localized_summary ?? $advisory->summary;

        $unknownSeverity = match (true) {
            $advisory->severity !== Severity::Unknown => null,
            $advisory->classified_by === null => __('Chưa phân loại được mức độ. Đọc tin gốc để đánh giá.'),
            default => __('Hãng chưa nêu mức độ.'),
        };

        return [
            ...array_map(fn (string $change): array => ['textParagraph' => ['text' => '<b>'.e($change).'</b>']], $changes),
            ...($previousSeverity === null ? [] : [['textParagraph' => ['text' => '<b>'.e(__('Mức độ tăng từ :from lên :to.', ['from' => $previousSeverity->getLabel(), 'to' => $advisory->severity->getLabel()])).'</b>']]]),
            ['textParagraph' => ['text' => e($opening)]],
            ...($unknownSeverity === null ? [] : [['textParagraph' => ['text' => e($unknownSeverity)]]]),
            ...(filled($summary) ? [['textParagraph' => ['text' => e($summary)]]] : []),
            ...($plan === [] ? [] : [['textParagraph' => ['text' => e(implode(' · ', $plan))]]]),
            ['textParagraph' => ['text' => e(implode(' · ', $facts))]],
        ];
    }

    /**
     * @param  Collection<int, Advisory>  $others
     * @return list<array<string, mixed>> No section when there are none.
     */
    private function otherNoticesSection(Collection $others): array
    {
        if ($others->isEmpty()) {
            return [];
        }

        return [[
            'header' => __('Tin khác của cùng sự kiện'),
            'widgets' => $others
                ->take($this->maxListedItems())
                ->map(fn (Advisory $other): array => ['decoratedText' => array_filter([
                    'text' => e($this->severityTitle($other->severity, $other->title)),
                    'bottomLabel' => $other->published_at === null ? null : __('Đăng lúc :time', ['time' => LocalTime::format($other->published_at)]),
                    'wrapText' => true,
                    'button' => filled($other->url) ? ['text' => __('Mở nguồn tin'), 'onClick' => ['openLink' => ['url' => $other->url]]] : null,
                ])])
                ->values()
                ->all(),
        ]];
    }

    /**
     * E.g. "[Critical] Upcoming PHP Security Release"; no tag when the severity is unknown.
     */
    private function severityTitle(Severity $severity, string $title): string
    {
        return $severity === Severity::Unknown ? $title : "[{$severity->getLabel()}] {$title}";
    }

    /**
     * The daily digest, or the weekly summary when `weekly` is set: only the weekly one lists every open finding below
     * the immediate severities, the daily one counts them. Incidents no admin assessed for the team yet are listed
     * apart from those waiting for a fix, with a link to assess them.
     *
     * @param  array{weekly: bool, new: Collection<int, Finding>, reminders: Collection<int, Finding>, overdue: Collection<int, Finding>, open: Collection<int, Finding>, resolved: Collection<int, Finding>, pre_notices: Collection<int, Advisory>, ending: Collection<int, array{runtime: Runtime, date: string}>, unknown: int, outdated: int, missing_lockfiles: int, unreadable_lockfiles: int, new_unreadable_lockfiles: int, awaiting_patch: Collection<int, array{incident: Incident, notice: Advisory, branches: list<string>, candidates: array<string, string>, needs_review: bool}>, awaiting_patch_news: bool, incident_reminders: Collection<int, array{incident: Incident, notice: Advisory, branches: list<string>, candidates: array<string, string>, needs_review: bool}>}  $digest
     * @return array<string, mixed>
     */
    public function digest(Team $team, array $digest, string $date): array
    {
        $sections = [];
        $summary = collect([
            'Mới: :count' => $digest['new']->count(),
            'Đang mở: :count' => $digest['new']->count() + $digest['reminders']->count() + $digest['overdue']->count() + $digest['open']->count(),
            'Đã tự đóng: :count' => $digest['resolved']->count(),
            'Runtime thiếu dữ liệu: :count' => $digest['unknown'],
            'Runtime có bản mới hơn: :count' => $digest['outdated'],
            'Service chưa có lockfile: :count' => $digest['missing_lockfiles'],
            'Lockfile không đọc được: :count' => $digest['unreadable_lockfiles'],
        ])
            ->filter()
            ->map(fn (int $count, string $line): string => __($line, ['count' => $count]))
            ->join(' · ');

        if ($summary !== '') {
            $sections[] = ['widgets' => [['textParagraph' => ['text' => e($summary)]]]];
        }

        $findingSections = [
            __('Finding mới') => $digest['new'],
            __('Cần xử lý, vẫn đang mở') => $digest['reminders'],
            __('Quá hạn xử lý') => $digest['overdue'],
            ...($digest['weekly'] ? [__('Vẫn đang mở') => $digest['open']] : []),
        ];

        foreach ($findingSections as $header => $findings) {
            $sections = [...$sections, ...$this->findingSection($header, $findings)];
        }

        if (! $digest['weekly'] && $digest['open']->isNotEmpty()) {
            $sections[] = ['widgets' => [['textParagraph' => ['text' => e(__('Còn :count finding khác đang mở (mức thấp hơn hoặc đã đọc), liệt kê trong bản tổng kết tuần.', ['count' => $digest['open']->count()]))]]]];
        }

        if ($digest['pre_notices']->isNotEmpty()) {
            $sections[] = [
                'header' => __('Tin bảo mật từ hãng'),
                'widgets' => [
                    ...$digest['pre_notices']
                        ->take($this->maxListedItems())
                        ->map(fn (Advisory $advisory): array => ['decoratedText' => array_filter([
                            'topLabel' => $advisory->technology->getLabel(),
                            'text' => e("[{$advisory->severity->getLabel()}] {$advisory->title}"),
                            'wrapText' => true,
                            'button' => filled($advisory->url) ? ['text' => __('Mở nguồn tin'), 'onClick' => ['openLink' => ['url' => $advisory->url]]] : null,
                        ])])
                        ->values()
                        ->all(),
                    ...$this->moreWidget($digest['pre_notices']->count()),
                ],
            ];
        }

        $sections = [
            ...$sections,
            ...$this->awaitingPatchSection(__('Sự kiện chờ admin đánh giá ảnh hưởng'), $digest['incident_reminders']),
            ...$this->awaitingPatchSection(__('Đang chờ bản vá'), $digest['awaiting_patch']),
        ];

        if ($digest['incident_reminders']->isNotEmpty()) {
            $sections[] = ['widgets' => [
                ['textParagraph' => ['text' => e(__('Nhắc lại mỗi ngày cho tới khi admin đánh dấu đã đọc và đánh giá ảnh hưởng tới team.'))]],
                $this->buttons([__('Đánh giá ảnh hưởng') => $this->incidentsUrl(['team' => $team->id, 'attention' => 1])]),
            ]];
        }

        if ($digest['ending']->isNotEmpty()) {
            $sections[] = [
                'header' => __('Sắp hết hỗ trợ'),
                'widgets' => $digest['ending']
                    ->take($this->maxListedItems())
                    ->map(fn (array $item): array => $this->serviceWidget($item['runtime']->deployment->service, __(':runtime hết hỗ trợ từ :date', ['runtime' => $item['runtime']->displayName(), 'date' => $item['date']])))
                    ->values()
                    ->all(),
            ];
        }

        $sections = [...$sections, ...$this->findingSection(__('Đã tự đóng'), $digest['resolved'])];

        if ($digest['new']->isNotEmpty() || $digest['reminders']->isNotEmpty()) {
            $sections[] = ['widgets' => [$this->buttons([__('Xem và đánh dấu đã đọc') => $this->findingsUrl(['team' => $team->id, 'status' => 'unread'])])]];
        }

        $title = $digest['weekly'] ? __('Tổng kết tuần bảo mật :date', ['date' => $date]) : __('Bản tổng hợp bảo mật :date', ['date' => $date]);

        return $this->card("digest-{$team->id}", $title, $team->name, $sections);
    }

    /**
     * @param  list<string>  $lines
     * @param  array<string, string>  $links  Button text => URL.
     * @return array<string, mixed>
     */
    public function adminAlert(string $title, array $lines, array $links = []): array
    {
        $widgets = array_map(fn (string $line): array => ['textParagraph' => ['text' => e($line)]], $lines);

        if ($links !== []) {
            $widgets[] = $this->buttons($links);
        }

        return $this->card('admin', $title, __('Security Manager · cảnh báo hệ thống'), [['widgets' => $widgets]]);
    }

    public function serviceUrl(Service $service): string
    {
        return route('admin.services.edit', $service);
    }

    /**
     * @param  array<string, int|string>  $filters
     */
    public function findingsUrl(array $filters): string
    {
        return route('admin.findings.index', $filters);
    }

    /**
     * @param  array<string, int|string>  $filters
     */
    public function incidentsUrl(array $filters): string
    {
        return route('admin.incidents.index', $filters);
    }

    /**
     * Incidents waiting for a fix for the team, each by its most severe pre-notice, with the branches still waiting and
     * the security releases that may be the fix.
     *
     * @param  Collection<int, array{incident: Incident, notice: Advisory, branches: list<string>, candidates: array<string, string>, needs_review: bool}>  $items
     * @return list<array<string, mixed>> No section when there are none.
     */
    private function awaitingPatchSection(string $header, Collection $items): array
    {
        if ($items->isEmpty()) {
            return [];
        }

        return [[
            'header' => $header,
            'widgets' => [
                ...$items
                    ->take($this->maxListedItems())
                    ->map(fn (array $item): array => ['decoratedText' => array_filter([
                        'topLabel' => $item['incident']->technology->getLabel(),
                        'text' => e($this->severityTitle($item['notice']->severity, $item['incident']->title)),
                        'bottomLabel' => implode(' · ', array_filter([
                            __('Nhánh :branches', ['branches' => implode(', ', $item['branches'])]),
                            $item['candidates'] === [] ? null : __('có thể là bản vá: :versions (hãng chưa xác nhận)', ['versions' => implode(', ', $item['candidates'])]),
                            $item['needs_review'] ? __('cần rà soát: quá :days ngày chưa có bản vá', ['days' => config('psm.incidents.review_after_days')]) : null,
                        ])),
                        'wrapText' => true,
                        'button' => filled($item['notice']->url) ? ['text' => __('Mở nguồn tin'), 'onClick' => ['openLink' => ['url' => $item['notice']->url]]] : null,
                    ])])
                    ->values()
                    ->all(),
                ...$this->moreWidget($items->count()),
            ],
        ]];
    }

    /**
     * @param  Collection<int, Finding>  $findings
     * @return list<array<string, mixed>> No section when there are no findings.
     */
    private function findingSection(string $header, Collection $findings): array
    {
        if ($findings->isEmpty()) {
            return [];
        }

        return [[
            'header' => $header,
            'collapsible' => $findings->count() > 5,
            'uncollapsibleWidgetsCount' => 5,
            'widgets' => [
                ...$findings
                    ->take($this->maxListedItems())
                    ->map(fn (Finding $finding): array => $this->serviceWidget($finding->deployment->service, implode(' · ', ["[{$finding->severity->getLabel()}] {$finding->title}", ...$this->findingStatus($finding)])))
                    ->values()
                    ->all(),
                ...$this->moreWidget($findings->count()),
            ],
        ]];
    }

    /**
     * The deadline and "Đã đọc" of an open finding, e.g. ["quá hạn 23/10/2026", "đã đọc"].
     *
     * @return list<string>
     */
    private function findingStatus(Finding $finding): array
    {
        if ($finding->state !== FindingState::Open) {
            return [];
        }

        return array_values(array_filter([
            match (true) {
                $finding->sla_due_at === null => null,
                $finding->sla_due_at->isPast() => __('quá hạn :date', ['date' => $this->date($finding->sla_due_at)]),
                default => __('hạn :date', ['date' => $this->date($finding->sla_due_at)]),
            },
            $finding->isAcknowledged() ? __('đã đọc') : null,
        ]));
    }

    /**
     * A date in the digest timezone, e.g. "23/10/2026"; "—" when there is none.
     */
    private function date(?CarbonInterface $time): string
    {
        return $time === null ? '—' : $time->copy()->setTimezone(config('psm.digest.timezone'))->translatedFormat(__('d/m/Y'));
    }

    /**
     * E.g. "PHP 8.3.10 · Đã có bản vá bảo mật, bản mới nhất của nhánh là 8.3.14".
     */
    private function findingDetail(Finding $finding): string
    {
        $context = $finding->context ?? [];

        if (isset($context['technology'], $context['version'])) {
            $reason = isset($context['reason']) ? ' · '.__($context['reason'], $context['reason_context'] ?? []) : '';

            return "{$context['technology']} {$context['version']}{$reason}";
        }

        return $context['detail'] ?? $finding->title;
    }

    /**
     * @return array<string, mixed>
     */
    private function serviceWidget(Service $service, string $text, bool $isNew = false): array
    {
        return ['decoratedText' => [
            'topLabel' => $service->project->name,
            'text' => '<b>'.e($service->name).'</b>'.($isNew ? ' · '.e(__('mới')) : ''),
            'bottomLabel' => $text,
            'wrapText' => true,
            'button' => ['text' => __('Mở service'), 'onClick' => ['openLink' => ['url' => $this->serviceUrl($service)]]],
        ]];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function moreWidget(int $count): array
    {
        $hidden = $count - $this->maxListedItems();

        return $hidden > 0 ? [['textParagraph' => ['text' => e(__('… và :count mục khác', ['count' => $hidden]))]]] : [];
    }

    /**
     * @param  array<string, string>  $links
     * @return array<string, mixed>
     */
    private function buttons(array $links): array
    {
        return ['buttonList' => ['buttons' => collect($links)
            ->map(fn (string $url, string $text): array => ['text' => $text, 'onClick' => ['openLink' => ['url' => $url]]])
            ->values()
            ->all()]];
    }

    /**
     * Sections without widgets are left out: Google Chat rejects a card with an empty section, e.g. the services of a
     * team that runs none of the technology.
     *
     * @param  list<array<string, mixed>>  $sections
     * @return array<string, mixed>
     */
    private function card(string $cardId, string $title, string $subtitle, array $sections): array
    {
        $sections = array_values(array_filter($sections, fn (array $section): bool => ($section['widgets'] ?? []) !== []));

        return [
            'text' => $title,
            'cardsV2' => [[
                'cardId' => $cardId,
                'card' => [
                    'header' => ['title' => $title, 'subtitle' => $subtitle],
                    'sections' => $sections,
                ],
            ]],
        ];
    }

    private function maxListedItems(): int
    {
        return config('psm.alerts.max_listed_items');
    }
}
