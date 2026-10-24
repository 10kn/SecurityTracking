<?php

namespace App\Http\Resources\Admin;

use App\Models\Finding;
use App\Models\Lockfile;
use App\Models\Runtime;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * A row of a services table: what runs in production and how it was evaluated.
 *
 * Load EAGER_LOADS and count open findings as open_findings_count first; the project and its team are included
 * when loaded.
 *
 * @mixin Service
 *
 * @property int $open_findings_count
 */
class ServiceResource extends JsonResource
{
    public const array EAGER_LOADS = ['productionDeployment.runtimes.technology', 'productionDeployment.lockfiles', 'productionDeployment.openPackageFindings'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $deployment = $this->productionDeployment;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'kind' => $this->kind->getLabel(),
            'exposure' => $this->exposure->getLabel(),
            'repository_path' => $this->repository_path,
            'project' => $this->whenLoaded('project', fn (): array => [
                'id' => $this->project->id,
                'name' => $this->project->name,
                'team' => $this->project->relationLoaded('team') ? $this->project->team->name : null,
            ]),
            'runtimes' => $this->runtimes($deployment->runtimes ?? collect()),
            'lockfiles' => $this->lockfiles($deployment->lockfiles ?? collect(), $deployment->openPackageFindings ?? collect()),
            'open_findings_count' => $this->open_findings_count,
            'updated_at' => $deployment?->updated_at?->diffForHumans(),
        ];
    }

    /**
     * E.g. "PHP 8.3.12" and its status, sorted by technology.
     *
     * @param  Collection<int, Runtime>  $runtimes
     * @return list<array{name: string, status: array{label: string, tone: string}, reason: string|null}>
     */
    private function runtimes(Collection $runtimes): array
    {
        return $runtimes
            ->sortBy(fn (Runtime $runtime): string => $runtime->technology->name)
            ->map(fn (Runtime $runtime): array => [
                'name' => $runtime->displayName(),
                'status' => $runtime->status->dot(),
                'reason' => $runtime->statusReasonText(),
            ])
            ->values()
            ->all();
    }

    /**
     * One entry per lockfile with its package count and how many of its packages have an open vulnerability. A
     * lockfile not read yet, or one that cannot be read, shows as missing data.
     *
     * @param  Collection<int, Lockfile>  $lockfiles
     * @param  Collection<int, Finding>  $openPackageFindings
     * @return list<array{path: string, state: array{label: string, tone: string}|null, packages: string|null, vulnerable: array{label: string, tone: string}|null, error: string|null}>
     */
    private function lockfiles(Collection $lockfiles, Collection $openPackageFindings): array
    {
        $vulnerablePackages = $openPackageFindings
            ->groupBy(fn (Finding $finding): string => (string) ($finding->context['lockfile'] ?? ''))
            ->map(fn (Collection $findings): int => $findings->unique(fn (Finding $finding): string => (string) ($finding->context['package'] ?? ''))->count());

        return $lockfiles
            ->sortBy('path')
            ->map(function (Lockfile $lockfile) use ($vulnerablePackages): array {
                $vulnerable = $vulnerablePackages->get($lockfile->path, 0);
                $isReady = $lockfile->isParsed() && ! $lockfile->isUnreadable();

                return [
                    'path' => $lockfile->path,
                    'state' => match (true) {
                        $lockfile->isUnreadable() => ['label' => __('Không đọc được'), 'tone' => 'unknown'],
                        ! $lockfile->isParsed() => ['label' => __('Đang chờ quét'), 'tone' => 'unknown'],
                        default => null,
                    },
                    'packages' => $isReady ? __(':count package', ['count' => $lockfile->package_count ?? 0]) : null,
                    'vulnerable' => $isReady && $vulnerable > 0 ? ['label' => __(':count có lỗ hổng', ['count' => $vulnerable]), 'tone' => 'danger'] : null,
                    'error' => $lockfile->parse_error,
                ];
            })
            ->values()
            ->all();
    }
}
