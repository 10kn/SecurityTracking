<?php

namespace App\Services;

use App\Enums\ReleaseSource;
use App\Enums\RuntimeStatus;
use App\Models\RuntimeBranch;
use App\Models\RuntimeRelease;
use App\Models\Technology;
use App\Support\Version;
use Illuminate\Support\Collection;

/**
 * Applies the runtime rules of handover section 5.3, first match wins:
 * unknown → eol → (unknown: version not in the catalog) → outdated_security → outdated → ok.
 *
 * Unknown is never treated as ok: a missing or unreadable version, a branch that is not in the catalog and a catalog
 * that has not synced within its threshold all evaluate as unknown.
 */
class RuntimeEvaluator
{
    /**
     * @var array<int, Collection<string, RuntimeBranch>>
     */
    private array $branches = [];

    /**
     * @var array<string, Collection<int, RuntimeRelease>>
     */
    private array $releases = [];

    /**
     * @var array<int, bool>
     */
    private array $catalogIsFresh = [];

    public function evaluate(Technology $technology, ?string $rawVersion): RuntimeEvaluation
    {
        if (blank($rawVersion)) {
            return new RuntimeEvaluation(RuntimeStatus::Unknown, 'Chưa nhập version');
        }

        $version = Version::normalize($rawVersion);

        if ($version === null) {
            return new RuntimeEvaluation(RuntimeStatus::Unknown, 'Không đọc được version ":version"', ['version' => trim($rawVersion)]);
        }

        $branches = $this->branchesOf($technology);

        if (! $this->catalogIsFresh($technology) || $branches->isEmpty()) {
            return new RuntimeEvaluation(RuntimeStatus::Unknown, 'Danh mục version :technology chưa đồng bộ hoặc đang lỗi', ['technology' => $technology->name], $version);
        }

        $branchName = Version::branchOf($version, $branches->keys());

        if ($branchName === null) {
            return new RuntimeEvaluation(RuntimeStatus::Unknown, 'Không có nhánh của :version trong danh mục :technology', ['version' => $version, 'technology' => $technology->name], $version);
        }

        /** @var RuntimeBranch $branch */
        $branch = $branches->get($branchName);
        $latest = $branch->latest_version;

        if (! Version::isReleaseOf($version, $branchName)) {
            return new RuntimeEvaluation(RuntimeStatus::Unknown, 'Version :version chưa đủ chi tiết, cần dạng :example', ['version' => $version, 'example' => $latest ?? $branchName.'.0'], $version, $branchName);
        }

        if ($branch->is_eol || $branch->security_support_until?->isBefore(today())) {
            return $branch->security_support_until === null
                ? new RuntimeEvaluation(RuntimeStatus::Eol, 'Nhánh :branch đã hết hỗ trợ', ['branch' => $branchName], $version, $branchName)
                : new RuntimeEvaluation(RuntimeStatus::Eol, 'Nhánh :branch hết hỗ trợ từ :date', ['branch' => $branchName, 'date' => $branch->security_support_until->toDateString()], $version, $branchName);
        }

        if ($latest !== null && Version::isNewer($version, $latest)) {
            return new RuntimeEvaluation(RuntimeStatus::Unknown, 'Version :version mới hơn bản mới nhất đã biết (:latest)', ['version' => $version, 'latest' => $latest], $version, $branchName);
        }

        $releases = $this->releasesOf($technology, $branchName);

        if ($technology->release_source !== ReleaseSource::Endoflife && $releases->isNotEmpty() && ! $releases->contains('version', $version)) {
            return new RuntimeEvaluation(RuntimeStatus::Unknown, 'Không có version :version trong danh mục :source', ['version' => $version, 'source' => $technology->release_source->getLabel()], $version, $branchName);
        }

        if ($this->hasNewerSecurityRelease($branch, $releases, $version)) {
            return new RuntimeEvaluation(RuntimeStatus::OutdatedSecurity, 'Đã có bản vá bảo mật, bản mới nhất của nhánh là :latest', ['latest' => (string) $latest], $version, $branchName);
        }

        if ($latest !== null && Version::isNewer($latest, $version)) {
            return new RuntimeEvaluation(RuntimeStatus::Outdated, 'Có bản mới hơn: :latest', ['latest' => $latest], $version, $branchName);
        }

        return new RuntimeEvaluation(RuntimeStatus::Ok, 'Đang dùng bản mới nhất của nhánh :branch', ['branch' => $branchName], $version, $branchName);
    }

    /**
     * A newer release in the branch fixes vulnerabilities. When the source does not say (null flag), a newer release
     * counts as a security fix if the branch only receives security fixes, or if its support phase is unknown:
     * reporting one fix too many is safer than missing one.
     *
     * @param  Collection<int, RuntimeRelease>  $releases
     */
    private function hasNewerSecurityRelease(RuntimeBranch $branch, Collection $releases, string $version): bool
    {
        $isSecurityOnlyOrUnknownPhase = $branch->active_support_until === null || $branch->active_support_until->isBefore(today());

        return $releases->contains(fn (RuntimeRelease $release): bool => Version::isNewer($release->version, $version)
            && ($release->is_security_release === true || ($release->is_security_release === null && $isSecurityOnlyOrUnknownPhase)));
    }

    /**
     * @return Collection<string, RuntimeBranch>
     */
    private function branchesOf(Technology $technology): Collection
    {
        return $this->branches[$technology->id] ??= $technology->branches()->get()->keyBy('branch');
    }

    /**
     * @return Collection<int, RuntimeRelease>
     */
    private function releasesOf(Technology $technology, string $branch): Collection
    {
        return $this->releases["{$technology->id}:{$branch}"] ??= $technology->releases()->where('branch', $branch)->get();
    }

    private function catalogIsFresh(Technology $technology): bool
    {
        return $this->catalogIsFresh[$technology->id] ??= $technology->catalogHeartbeat()?->isStale() === false;
    }
}
