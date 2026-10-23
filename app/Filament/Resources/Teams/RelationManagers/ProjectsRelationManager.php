<?php

namespace App\Filament\Resources\Teams\RelationManagers;

use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Project;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ProjectsRelationManager extends RelationManager
{
    protected static string $relationship = 'projects';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Projects');
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount('services'))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label(__('Project'))
                    ->weight('medium')
                    ->searchable(),
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
            ->recordUrl(fn (Project $record): string => ProjectResource::getUrl('view', ['record' => $record]))
            ->emptyStateHeading(__('Team chưa có project nào'));
    }
}
