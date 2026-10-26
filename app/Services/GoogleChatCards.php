<?php

namespace App\Services;

use App\Enums\FindingState;
use App\Enums\Severity;
use App\Models\Advisory;
use App\Models\Finding;
use App\Models\Runtime;
use App\Models\Service;
use App\Models\Team;
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
     * "Cảnh báo chuẩn bị": a vendor's security news for one team, listing the team's services that run the technology.
     *
     * @param  Collection<int, Runtime>  $runtimes  The team's runtimes of the technology, with `deployment.service.project`.
     * @param  Severity|null  $previousSeverity  The severity of the card the team got before, when it rose since.
     * @return array<string, mixed>
     */
    public function preNotice(Team $team, Advisory $advisory, Collection $runtimes, ?Severity $previousSeverity = null): array
    {
        $technology = $advisory->technology;
        $title = $advisory->severity === Severity::Unknown ? $advisory->title : "[{$advisory->severity->getLabel()}] {$advisory->title}";

        $facts = array_filter([
            __('Nguồn: :source', ['source' => $technology->releaseSourceLabel()]),
            $advisory->published_at === null ? null : __('Đăng lúc :time UTC', ['time' => $advisory->published_at->utc()->translatedFormat(__('d/m/Y H:i'))]),
            $advisory->cvss_score === null ? null : "CVSS {$advisory->cvss_score}",
            $advisory->severity_basis?->getLabel(),
        ]);

        $plan = array_filter([
            $advisory->planned_release_at === null ? null : __('Dự kiến phát hành :time UTC', ['time' => $advisory->planned_release_at->utc()->translatedFormat(__('d/m/Y H:i'))]),
            blank($advisory->affected_branches) ? null : __('Nhánh bị ảnh hưởng: :branches', ['branches' => implode(', ', $advisory->affected_branches)]),
        ]);
        $summary = $advisory->localized_summary ?? $advisory->summary;

        $unknownSeverity = match (true) {
            $advisory->severity !== Severity::Unknown => null,
            $advisory->classified_by === null => __('Chưa phân loại được mức độ. Đọc tin gốc để đánh giá.'),
            default => __('Hãng chưa nêu mức độ.'),
        };

        $widgets = [
            ...($previousSeverity === null ? [] : [['textParagraph' => ['text' => '<b>'.e(__('Mức độ tăng từ :from lên :to.', ['from' => $previousSeverity->getLabel(), 'to' => $advisory->severity->getLabel()])).'</b>']]]),
            ['textParagraph' => ['text' => e(__('Hãng vừa đăng tin bảo mật về :technology. Kiểm tra và chuẩn bị nâng cấp các service dưới đây.', ['technology' => $technology->getLabel()]))]],
            ...($unknownSeverity === null ? [] : [['textParagraph' => ['text' => e($unknownSeverity)]]]),
            ...(filled($summary) ? [['textParagraph' => ['text' => e($summary)]]] : []),
            ...($plan === [] ? [] : [['textParagraph' => ['text' => e(implode(' · ', $plan))]]]),
            ['textParagraph' => ['text' => e(implode(' · ', $facts))]],
        ];

        $services = $runtimes
            ->sortBy(fn (Runtime $runtime): string => $runtime->deployment->service->name)
            ->take($this->maxListedItems())
            ->map(fn (Runtime $runtime): array => $this->serviceWidget($runtime->deployment->service, $runtime->displayName()))
            ->values()
            ->all();

        $sections = [
            ['widgets' => $widgets],
            ['header' => __(':count service đang chạy :technology', ['count' => $runtimes->count(), 'technology' => $technology->getLabel()]), 'widgets' => [...$services, ...$this->moreWidget($runtimes->count())]],
        ];

        if (filled($advisory->url)) {
            $sections[] = ['widgets' => [$this->buttons([__('Mở nguồn tin') => $advisory->url])]];
        }

        return $this->card("pre-notice-{$advisory->id}", $title, __('Cảnh báo chuẩn bị · :team', ['team' => $team->name]), $sections);
    }

    /**
     * The daily digest, or the weekly summary when `weekly` is set: only the weekly one lists every open finding below
     * the immediate severities, the daily one counts them.
     *
     * @param  array{weekly: bool, new: Collection<int, Finding>, reminders: Collection<int, Finding>, overdue: Collection<int, Finding>, open: Collection<int, Finding>, resolved: Collection<int, Finding>, pre_notices: Collection<int, Advisory>, ending: Collection<int, array{runtime: Runtime, date: string}>, unknown: int, outdated: int, missing_lockfiles: int, unreadable_lockfiles: int, new_unreadable_lockfiles: int}  $digest
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
