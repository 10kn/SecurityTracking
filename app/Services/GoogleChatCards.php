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
     * marked new.
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

        $links = array_filter([
            __('Mở nguồn tin') => $first->context['source_url'] ?? null,
            __('Đánh dấu đã đọc') => $this->findingsUrl(['team' => $team->id, 'ids' => implode(',', $findings->sortBy('id')->modelKeys())]),
        ], filled(...));

        $sections[] = ['widgets' => [
            ['textParagraph' => ['text' => e(__('Nhắc lại trong bản tổng hợp mỗi ngày cho tới khi admin đánh dấu đã đọc. Hạn xử lý: :date.', ['date' => $this->date($first->sla_due_at)]))]],
            $this->buttons($links),
        ]];

        return $this->card("finding-{$first->id}", "[{$severity}] {$first->title}", $team->name, $sections);
    }

    /**
     * "Cảnh báo chuẩn bị": a vendor's security news for one team, one card per incident, led by its most severe
     * pre-notice and listing the team's services that run the technology with whether the vendor said their branch is
     * affected.
     *
     * @param  Collection<int, Advisory>  $others  The incident's other pre-notices that count now.
     * @param  Collection<int, Runtime>  $runtimes  The team's runtimes of the technology, with `deployment.service.project`.
     * @param  Severity|null  $previousSeverity  The severity of the card the team got before, when it rose since.
     * @return array<string, mixed>
     */
    public function preNotice(Team $team, Incident $incident, Advisory $advisory, Collection $others, Collection $runtimes, ?Severity $previousSeverity = null): array
    {
        $technology = $advisory->technology;

        $widgets = $this->incidentWidgets($incident, $advisory, $previousSeverity, __('Hãng vừa đăng tin bảo mật về :technology. Kiểm tra và chuẩn bị nâng cấp các service dưới đây.', ['technology' => $technology->getLabel()]));

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
     * @return array<string, mixed>
     */
    public function patchAvailable(Team $team, Incident $incident, Advisory $advisory, Collection $others, Collection $runtimes, array $patches, array $knownPatches, ?Severity $previousSeverity, ?CarbonInterface $deadline): array
    {
        $technology = $advisory->technology;
        $severity = $advisory->severity;
        $versions = implode(', ', array_values($patches));

        $widgets = [
            ['textParagraph' => ['text' => '<b>'.e($advisory->title).'</b>']],
            ...$this->incidentWidgets($incident, $advisory, $previousSeverity, __('Hãng đã xác nhận bản vá :versions. Có bản vá không có nghĩa service đã an toàn: chỉ an toàn sau khi nâng cấp.', ['versions' => $versions])),
        ];
        $widgets[] = ['textParagraph' => ['text' => '<b>'.e(__('Hạn xử lý: :date.', ['date' => $this->date($deadline)])).'</b>']];

        $targets = array_replace($knownPatches, $patches);
        [$patched, $waiting] = $runtimes
            ->sortBy(fn (Runtime $runtime): string => $runtime->deployment->service->name)
            ->partition(fn (Runtime $runtime): bool => isset($targets[$runtime->branch]));
        [$toUpgrade, $atFix] = $patched->partition(fn (Runtime $runtime): bool => $runtime->version === null || Version::isNewer($targets[$runtime->branch], $runtime->version));

        $sections = [
            ['widgets' => $widgets],
            [
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
            ],
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
     * What a card says about an incident: a rise in severity, the opening line, why the severity is unknown, the
     * summary, the planned release and affected branches, and the facts of the leading pre-notice.
     *
     * @return list<array<string, mixed>>
     */
    private function incidentWidgets(Incident $incident, Advisory $advisory, ?Severity $previousSeverity, string $opening): array
    {
        $plannedReleaseAt = $incident->planned_release_at ?? $advisory->planned_release_at;

        $facts = array_filter([
            __('Nguồn: :source', ['source' => $advisory->technology->releaseSourceLabel()]),
            $advisory->published_at === null ? null : __('Đăng lúc :time UTC', ['time' => $advisory->published_at->utc()->translatedFormat(__('d/m/Y H:i'))]),
            $advisory->cvss_score === null ? null : "CVSS {$advisory->cvss_score}",
            $advisory->severity_basis?->getLabel(),
        ]);

        $plan = array_filter([
            $plannedReleaseAt === null ? null : __('Dự kiến phát hành :time UTC', ['time' => $plannedReleaseAt->utc()->translatedFormat(__('d/m/Y H:i'))]),
            blank($incident->affected_branches) ? null : __('Nhánh bị ảnh hưởng: :branches', ['branches' => implode(', ', $incident->affected_branches)]),
        ]);
        $summary = $advisory->localized_summary ?? $advisory->summary;

        $unknownSeverity = match (true) {
            $advisory->severity !== Severity::Unknown => null,
            $advisory->classified_by === null => __('Chưa phân loại được mức độ. Đọc tin gốc để đánh giá.'),
            default => __('Hãng chưa nêu mức độ.'),
        };

        return [
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
                    'bottomLabel' => $other->published_at === null ? null : __('Đăng lúc :time UTC', ['time' => $other->published_at->utc()->translatedFormat(__('d/m/Y H:i'))]),
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
     * the immediate severities, the daily one counts them.
     *
     * @param  array{weekly: bool, new: Collection<int, Finding>, reminders: Collection<int, Finding>, overdue: Collection<int, Finding>, open: Collection<int, Finding>, resolved: Collection<int, Finding>, pre_notices: Collection<int, Advisory>, ending: Collection<int, array{runtime: Runtime, date: string}>, unknown: int, outdated: int, missing_lockfiles: int, unreadable_lockfiles: int, new_unreadable_lockfiles: int, awaiting_patch: Collection<int, array{incident: Incident, notice: Advisory, branches: list<string>, candidates: array<string, string>, needs_review: bool}>, awaiting_patch_news: bool}  $digest
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

        if ($digest['awaiting_patch']->isNotEmpty()) {
            $sections[] = [
                'header' => __('Đang chờ bản vá'),
                'widgets' => [
                    ...$digest['awaiting_patch']
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
                    ...$this->moreWidget($digest['awaiting_patch']->count()),
                ],
            ];
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
     * @param  list<array<string, mixed>>  $sections
     * @return array<string, mixed>
     */
    private function card(string $cardId, string $title, string $subtitle, array $sections): array
    {
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
