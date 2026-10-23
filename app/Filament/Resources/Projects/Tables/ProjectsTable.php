<?php

namespace App\Filament\Resources\Projects\Tables;

use App\Models\Project;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProjectsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('team')->withCount('services'))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label(__('Project'))
                    ->description(fn (Project $record): string => $record->slug)
                    ->weight('medium')
                    ->searchable(['name', 'slug'])
                    ->sortable(),
                TextColumn::make('team.name')
                    ->label(__('Team'))
                    ->sortable(),
                TextColumn::make('repository_url')
                    ->label(__('Repository'))
                    ->fontFamily('mono')
                    ->color('gray')
                    ->placeholder('—'),
                TextColumn::make('services_count')
                    ->label(__('Services'))
                    ->numeric()
                    ->alignEnd(),
            ])
            ->filters([
                SelectFilter::make('team')
                    ->label(__('Team'))
                    ->relationship('team', 'name'),
            ])
            ->recordActions([
                EditAction::make()->label(__('Sửa')),
            ])
            ->emptyStateHeading(__('Chưa có project nào'))
            ->emptyStateDescription(__('Tạo project rồi thêm service để hệ thống bắt đầu theo dõi.'));
    }
}
