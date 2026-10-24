<?php

namespace App\Services;

use App\Enums\Severity;
use App\Filament\Resources\Services\ServiceResource;
use App\Models\Advisory;
use App\Models\Finding;
use App\Models\Runtime;
use App\Models\Service;
use App\Models\Team;
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
            __('Mở tin cảnh báo của hãng') => $first->context['pre_notice_url'] ?? null,
        ], filled(...));

        if ($links !== []) {
            $sections[] = ['widgets' => [$this->buttons($links)]];
        }

        return $this->card("finding-{$first->id}", "[{$severity}] {$first->title}", $team->name, $sections);
    }

    /**
     * "Cảnh báo chuẩn bị": a vendor's security news for one team, listing the team's services that run the technology.
     *
     * @param  Collection<int, Runtime>  $runtimes  The team's runtimes of the technology, with `deployment.service.project`.
     * @return array<string, mixed>
     */
    public function preNotice(Team $team, Advisory $advisory, Collection $runtimes): array
    {
        $technology = $advisory->technology;
        $title = $advisory->severity === Severity::Unknown ? $advisory->title : "[{$advisory->severity->getLabel()}] {$advisory->title}";

        $facts = array_filter([
            __('Nguồn: :source', ['source' => $technology->release_source->getLabel()]),
            $advisory->published_at === null ? null : __('Đăng lúc :time UTC', ['time' => $advisory->published_at->utc()->translatedFormat(__('d/m/Y H:i'))]),
            $advisory->cvss_score === null ? null : "CVSS {$advisory->cvss_score}",
        ]);

        $widgets = [
            ['textParagraph' => ['text' => e(__('Hãng vừa đăng tin bảo mật về :technology. Kiểm tra và chuẩn bị nâng cấp các service dưới đây.', ['technology' => $technology->name]))]],
            ...(filled($advisory->summary) ? [['textParagraph' => ['text' => e($advisory->summary)]]] : []),
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
            ['header' => __(':count service đang chạy :technology', ['count' => $runtimes->count(), 'technology' => $technology->name]), 'widgets' => [...$services, ...$this->moreWidget($runtimes->count())]],
        ];

        if (filled($advisory->url)) {
            $sections[] = ['widgets' => [$this->buttons([__('Mở nguồn tin') => $advisory->url])]];
        }

        return $this->card("pre-notice-{$advisory->id}", $title, __('Cảnh báo chuẩn bị · :team', ['team' => $team->name]), $sections);
    }

    /**
     * @param  array{new: Collection<int, Finding>, open: Collection<int, Finding>, resolved: Collection<int, Finding>, unknown: int, outdated: int, ending: Collection<int, array{runtime: Runtime, date: string}>, missing_lockfiles: int, unreadable_lockfiles: int}  $digest
     * @return array<string, mixed>
     */
    public function digest(Team $team, array $digest, string $date): array
    {
        $sections = [];
        $summary = collect([
            'Mới: :count' => $digest['new']->count(),
            'Đang mở: :count' => $digest['new']->count() + $digest['open']->count(),
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

        foreach ([
            __('Finding mới') => $digest['new'],
            __('Vẫn đang mở') => $digest['open'],
            __('Đã tự đóng') => $digest['resolved'],
        ] as $header => $findings) {
            if ($findings->isEmpty()) {
                continue;
            }

            $sections[] = [
                'header' => $header,
                'collapsible' => $findings->count() > 5,
                'uncollapsibleWidgetsCount' => 5,
                'widgets' => [
                    ...$findings
                        ->take($this->maxListedItems())
                        ->map(fn (Finding $finding): array => $this->serviceWidget($finding->deployment->service, "[{$finding->severity->getLabel()}] {$finding->title}"))
                        ->values()
                        ->all(),
                    ...$this->moreWidget($findings->count()),
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

        return $this->card("digest-{$team->id}", __('Bản tổng hợp bảo mật :date', ['date' => $date]), $team->name, $sections);
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
        return ServiceResource::getUrl('edit', ['record' => $service], panel: 'admin');
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
