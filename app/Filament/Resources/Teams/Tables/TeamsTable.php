<?php

namespace App\Filament\Resources\Teams\Tables;

use App\Enums\FindingState;
use App\Filament\Support\StatusDot;
use App\Models\Team;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class TeamsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('latestChatNotification')->withCount('projects'))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label(__('Team'))
                    ->description(fn (Team $record): string => $record->slug)
                    ->weight('medium')
                    ->searchable(['name', 'slug'])
                    ->sortable(),
                TextColumn::make('projects_count')
                    ->label(__('Projects'))
                    ->numeric()
                    ->alignEnd(),
                TextColumn::make('findings_count')
                    ->label(__('Finding mở'))
                    ->counts(['findings' => fn (Builder $query): Builder => $query->where('state', FindingState::Open)])
                    ->numeric()
                    ->color(fn (int $state): string => $state > 0 ? 'danger' : 'gray')
                    ->alignEnd(),
                TextColumn::make('google_chat')
                    ->label(__('Google Chat'))
                    ->state(fn (Team $record): HtmlString => StatusDot::make($record->googleChatStatus()->getLabel(), $record->googleChatStatus()->dotTone())),
            ])
            ->recordActions([
                EditAction::make()->label(__('Sửa')),
            ])
            ->emptyStateHeading(__('Chưa có team nào'))
            ->emptyStateDescription(__('Finding được gửi tới team sở hữu project bị ảnh hưởng.'));
    }
}
