<?php

namespace App\Filament\Resources\Services\Tables;

use App\Models\Service;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ServicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['project.team', ...ServiceColumns::EAGER_LOADS]))
            ->defaultSort('name')
            ->columns([
                ServiceColumns::name(),
                TextColumn::make('project.name')
                    ->label(__('Project'))
                    ->description(fn (Service $record): string => $record->project->team->name)
                    ->sortable(),
                ServiceColumns::runtimes(),
                ServiceColumns::lockfiles(),
                ServiceColumns::openFindings(),
                ServiceColumns::updatedAt(),
            ])
            ->filters([
                SelectFilter::make('project')
                    ->label(__('Project'))
                    ->relationship('project', 'name'),
            ])
            ->recordActions([
                EditAction::make()->label(__('Sửa')),
            ])
            ->emptyStateHeading(__('Chưa có service nào'))
            ->emptyStateDescription(__('Thêm service và nhập version runtime để hệ thống bắt đầu theo dõi.'));
    }
}
