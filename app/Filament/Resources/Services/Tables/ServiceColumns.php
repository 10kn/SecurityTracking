<?php

namespace App\Filament\Resources\Services\Tables;

use App\Enums\FindingState;
use App\Enums\RuntimeStatus;
use App\Filament\Support\StatusDot;
use App\Models\Finding;
use App\Models\Lockfile;
use App\Models\Runtime;
use App\Models\Service;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

/**
 * Columns shared by the services list and the Services tab of a project.
 *
 * Queries using these columns should eager load EAGER_LOADS.
 */
class ServiceColumns
{
    public const array EAGER_LOADS = ['productionDeployment.runtimes.technology', 'productionDeployment.lockfiles', 'productionDeployment.openPackageFindings'];

    public static function name(): TextColumn
    {
        return TextColumn::make('name')
            ->label(__('Service'))
            ->description(fn (Service $record): string => collect([$record->kind->getLabel(), $record->exposure->getLabel(), $record->repository_path])->join(' · '))
            ->weight('medium')
            ->searchable(['name', 'slug'])
            ->sortable();
    }

    /**
     * One line per runtime: "PHP 8.3.12" and its status. Without any runtime the service shows as missing data.
     */
    public static function runtimes(): TextColumn
    {
        return TextColumn::make('runtimes')
            ->label(__('Runtime'))
            ->state(function (Service $record): HtmlString {
                $runtimes = $record->productionDeployment->runtimes ?? collect();

                if ($runtimes->isEmpty()) {
                    return self::missing(__('Chưa nhập version'));
                }

                return new HtmlString($runtimes
                    ->sortBy(fn (Runtime $runtime): string => $runtime->technology->name)
                    ->map(fn (Runtime $runtime): string => '<span class="psm-cell-line" title="'.e($runtime->statusReasonText()).'"><span class="psm-mono">'.e($runtime->displayName()).'</span> '
                        .StatusDot::make($runtime->status->getLabel(), $runtime->status->dotTone())->toHtml().'</span>')
                    ->join(''));
            });
    }

    /**
     * One line per lockfile: its package count and, when any, how many packages have an open vulnerability. A
     * lockfile not read yet, or one that cannot be read, shows as missing data, like a service without a lockfile.
     */
    public static function lockfiles(): TextColumn
    {
        return TextColumn::make('lockfiles')
            ->label(__('Packages'))
            ->state(function (Service $record): HtmlString {
                $deployment = $record->productionDeployment;
                $lockfiles = $deployment->lockfiles ?? collect();

                if ($lockfiles->isEmpty()) {
                    return self::missing(__('Chưa có lockfile'));
                }

                $vulnerablePackages = ($deployment->openPackageFindings ?? collect())
                    ->groupBy(fn (Finding $finding): string => (string) ($finding->context['lockfile'] ?? ''))
                    ->map(fn (Collection $findings): int => $findings->unique(fn (Finding $finding): string => (string) ($finding->context['package'] ?? ''))->count());

                return new HtmlString($lockfiles
                    ->sortBy('path')
                    ->map(fn (Lockfile $lockfile): string => '<span class="psm-cell-line" title="'.e($lockfile->parse_error ?? '').'"><span class="psm-mono">'.e($lockfile->path).'</span> '
                        .self::lockfileState($lockfile, $vulnerablePackages->get($lockfile->path, 0)).'</span>')
                    ->join(''));
            });
    }

    /**
     * Open findings of every environment, red when there is any.
     */
    public static function openFindings(): TextColumn
    {
        return TextColumn::make('findings_count')
            ->label(__('Finding mở'))
            ->counts(['findings' => fn (Builder $query): Builder => $query->where('state', FindingState::Open)])
            ->numeric()
            ->color(fn (int $state): string => $state > 0 ? 'danger' : 'gray')
            ->alignEnd();
    }

    public static function updatedAt(): TextColumn
    {
        return TextColumn::make('productionDeployment.updated_at')
            ->label(__('Cập nhật'))
            ->since()
            ->color('gray')
            ->placeholder('—');
    }

    private static function lockfileState(Lockfile $lockfile, int $vulnerablePackages): string
    {
        if ($lockfile->isUnreadable()) {
            return StatusDot::make(__('Không đọc được'), 'unknown')->toHtml();
        }

        if (! $lockfile->isParsed()) {
            return StatusDot::make(__('Đang chờ quét'), 'unknown')->toHtml();
        }

        $count = '<span class="psm-muted">'.e(__(':count package', ['count' => $lockfile->package_count ?? 0])).'</span>';

        return $vulnerablePackages === 0
            ? $count
            : $count.' '.StatusDot::make(__(':count có lỗ hổng', ['count' => $vulnerablePackages]), 'danger')->toHtml();
    }

    private static function missing(string $reason): HtmlString
    {
        return new HtmlString(StatusDot::make(RuntimeStatus::Unknown->getLabel(), 'unknown')->toHtml()
            .'<span class="psm-cell-sub">'.e($reason).'</span>');
    }
}
