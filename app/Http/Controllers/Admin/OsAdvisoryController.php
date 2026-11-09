<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AdvisoryKind;
use App\Enums\LinuxDistribution;
use App\Enums\Severity;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\OsAdvisoryResource;
use App\Models\Advisory;
use App\Models\SourceHeartbeat;
use App\Support\LocalTime;
use App\Support\TableQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The security advisories of the operating systems followed (see psm:watch-os-advisories), newest first. Not applied
 * to projects yet: the admin reads them here and in the security news space. The search looks in the id, the title,
 * the CVE ids (see Advisory::matching()) and the names of the fixed packages; a Google Chat card links here with its
 * advisory's id as the search. Each feed's last successful poll is shown, so a silent feed is noticed.
 */
class OsAdvisoryController extends Controller
{
    public function index(Request $request): Response
    {
        $table = TableQuery::fromRequest($request, ['published_at', 'severity', 'title'], '-published_at');
        $os = LinuxDistribution::tryFrom($request->string('os')->toString());
        $release = $os === null ? null : ($request->string('release')->toString() ?: null);
        $severity = Severity::tryFrom($request->string('severity')->toString());
        $isKnownExploited = $request->boolean('kev');

        $advisories = $table->search(Advisory::where('kind', AdvisoryKind::OsAdvisory), ['external_id'], fn (Builder $query, string $pattern) => $query
            ->orWhereIn('id', Advisory::matching($pattern)->where('kind', AdvisoryKind::OsAdvisory)->select('id'))
            ->orWhereRaw("exists (select 1 from jsonb_array_elements(coalesce(advisories.fixed_packages, '[]'::jsonb)) as packages (package) where packages.package ->> 'name' ilike ?)", [$pattern]))
            ->when($os !== null, fn (Builder $query) => $query->where('operating_system', $os))
            ->when($release !== null, fn (Builder $query) => $query->whereJsonContains('os_releases', $release))
            ->when($severity !== null, fn (Builder $query) => $query->where('severity', $severity))
            ->when($isKnownExploited, fn (Builder $query) => $query->whereNotNull('known_exploited'))
            ->tap(fn (Builder $query) => match ($table->sort) {
                'severity' => $query->orderByRaw($this->severityOrder().' '.($table->descending ? 'asc' : 'desc')),
                default => $query->orderBy($table->sort, $table->direction()),
            })
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate($table->perPage)
            ->withQueryString();

        return Inertia::render('os-advisories/index', [
            'advisories' => OsAdvisoryResource::collection($advisories),
            'table' => [...$table->toArray(), 'os' => $os?->value, 'release' => $release, 'severity' => $severity?->value, 'kev' => $isKnownExploited ? 1 : null],
            'systems' => collect(LinuxDistribution::cases())->map(fn (LinuxDistribution $os): array => ['value' => $os->value, 'label' => $os->getLabel()])->all(),
            'releases' => $os === null ? [] : $this->releasesOf($os),
            'severities' => collect(Severity::cases())->map(fn (Severity $severity): array => ['value' => $severity->value, 'label' => $severity->getLabel()])->all(),
            'sources' => $this->sources(),
        ]);
    }

    /**
     * The releases the stored advisories of the operating system fix, newest first.
     *
     * @return list<array{value: string, label: string}>
     */
    private function releasesOf(LinuxDistribution $os): array
    {
        return DB::table('advisories')
            ->where('kind', AdvisoryKind::OsAdvisory->value)
            ->where('operating_system', $os->value)
            ->whereNotNull('os_releases')
            ->selectRaw('distinct jsonb_array_elements_text(os_releases) as release')
            ->pluck('release')
            ->sort(fn (string $a, string $b): int => version_compare($b, $a))
            ->map(fn (string $release): array => ['value' => $release, 'label' => "{$os->getLabel()} {$release}"])
            ->values()
            ->all();
    }

    /**
     * Each feed with its last successful poll and whether it is past its threshold; a feed never polled is stale.
     *
     * @return list<array{label: string, synced_at: string|null, is_stale: bool}>
     */
    private function sources(): array
    {
        $heartbeats = SourceHeartbeat::whereIn('source', array_map(fn (LinuxDistribution $os): string => $os->sourceKey(), LinuxDistribution::cases()))->get()->keyBy('source');

        return collect(LinuxDistribution::cases())->map(fn (LinuxDistribution $os): array => [
            'label' => $os->getLabel(),
            'synced_at' => LocalTime::format($heartbeats->get($os->sourceKey())?->last_success_at),
            'is_stale' => $heartbeats->get($os->sourceKey())?->isStale() ?? true,
        ])->all();
    }

    /**
     * Critical first by default.
     */
    private function severityOrder(): string
    {
        $cases = collect(Severity::cases())->map(fn (Severity $severity): string => "when '{$severity->value}' then {$severity->rank()}")->join(' ');

        return "case severity {$cases} else 0 end";
    }
}
