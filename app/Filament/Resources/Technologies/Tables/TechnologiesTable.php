<?php

namespace App\Filament\Resources\Technologies\Tables;

use App\Filament\Support\StatusDot;
use App\Jobs\SyncTechnologyCatalogJob;
use App\Models\Technology;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class TechnologiesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount(['branches', 'runtimes']))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label(__('Công nghệ'))
                    ->description(fn (Technology $record): string => $record->endoflife_product)
                    ->weight('medium')
                    ->searchable(['name', 'slug', 'endoflife_product'])
                    ->sortable(),
                TextColumn::make('release_source')
                    ->label(__('Nguồn bản phát hành')),
                TextColumn::make('branches_count')
                    ->label(__('Nhánh'))
                    ->numeric()
                    ->alignEnd(),
                TextColumn::make('runtimes_count')
                    ->label(__('Đang dùng'))
                    ->numeric()
                    ->alignEnd(),
                TextColumn::make('catalog')
                    ->label(__('Danh mục version'))
                    ->state(fn (Technology $record): HtmlString => self::catalogStatus($record)),
            ])
            ->recordActions([
                Action::make('sync')
                    ->label(__('Đồng bộ ngay'))
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
                    ->action(function (Technology $record): void {
                        SyncTechnologyCatalogJob::dispatch($record);

                        Notification::make()->success()->title(__('Đã đưa :technology vào hàng đợi đồng bộ.', ['technology' => $record->name]))->send();
                    }),
                EditAction::make()->label(__('Sửa')),
            ])
            ->emptyStateHeading(__('Chưa có công nghệ nào'))
            ->emptyStateDescription(__('Thêm công nghệ để chọn được khi nhập version cho service.'));
    }

    private static function catalogStatus(Technology $record): HtmlString
    {
        $status = $record->catalogStatus();
        $heartbeat = $record->catalogHeartbeat();
        $detail = $heartbeat?->last_success_at !== null
            ? __('lần cuối :time', ['time' => $heartbeat->last_success_at->diffForHumans()])
            : null;

        return new HtmlString(StatusDot::make($status->getLabel(), $status->dotTone())->toHtml()
            .($detail === null ? '' : '<span class="psm-cell-sub">'.e($detail).'</span>')
            .($heartbeat?->last_error === null ? '' : '<span class="psm-cell-sub">'.e($heartbeat->last_error).'</span>'));
    }
}
