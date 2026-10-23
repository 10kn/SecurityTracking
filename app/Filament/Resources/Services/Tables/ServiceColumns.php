<?php

namespace App\Filament\Resources\Services\Tables;

use App\Enums\RuntimeStatus;
use App\Filament\Support\StatusDot;
use App\Models\Lockfile;
use App\Models\Runtime;
use App\Models\Service;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\HtmlString;

/**
 * Columns shared by the services list and the Services tab of a project.
 *
 * Queries using these columns should eager load `productionDeployment.runtimes` and `productionDeployment.lockfiles`.
 */
class ServiceColumns
{
    public const array EAGER_LOADS = ['productionDeployment.runtimes', 'productionDeployment.lockfiles'];

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
                    ->sortBy(fn (Runtime $runtime): string => $runtime->language->value)
                    ->map(fn (Runtime $runtime): string => '<span class="psm-cell-line"><span class="psm-mono">'.e($runtime->displayName()).'</span> '
                        .StatusDot::make($runtime->status->getLabel(), $runtime->status->dotTone())->toHtml().'</span>')
                    ->join(''));
            });
    }

    public static function lockfiles(): TextColumn
    {
        return TextColumn::make('lockfiles')
            ->label(__('Packages'))
            ->state(function (Service $record): HtmlString {
                $lockfiles = $record->productionDeployment->lockfiles ?? collect();

                if ($lockfiles->isEmpty()) {
                    return self::missing(__('Chưa có lockfile'));
                }

                return new HtmlString($lockfiles
                    ->map(fn (Lockfile $lockfile): string => '<span class="psm-cell-line psm-mono">'.e($lockfile->path).'</span>')
                    ->join(''));
            });
    }

    public static function updatedAt(): TextColumn
    {
        return TextColumn::make('productionDeployment.updated_at')
            ->label(__('Cập nhật'))
            ->since()
            ->color('gray')
            ->placeholder('—');
    }

    private static function missing(string $reason): HtmlString
    {
        return new HtmlString(StatusDot::make(RuntimeStatus::Unknown->getLabel(), 'unknown')->toHtml()
            .'<span class="psm-cell-sub">'.e($reason).'</span>');
    }
}
