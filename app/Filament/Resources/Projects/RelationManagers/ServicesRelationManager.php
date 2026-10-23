<?php

namespace App\Filament\Resources\Projects\RelationManagers;

use App\Filament\Resources\Services\ServiceResource;
use App\Filament\Resources\Services\Tables\ServiceColumns;
use App\Models\Service;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ServicesRelationManager extends RelationManager
{
    protected static string $relationship = 'services';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Services');
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(ServiceColumns::EAGER_LOADS))
            ->defaultSort('name')
            ->columns([
                ServiceColumns::name(),
                ServiceColumns::runtimes(),
                ServiceColumns::lockfiles(),
                ServiceColumns::updatedAt(),
            ])
            ->headerActions([
                Action::make('createService')
                    ->label(__('Thêm service'))
                    ->url(fn (): string => ServiceResource::getUrl('create', ['project' => $this->getOwnerRecord()->getKey()])),
            ])
            ->recordUrl(fn (Service $record): string => ServiceResource::getUrl('edit', ['record' => $record]))
            ->emptyStateHeading(__('Project chưa có service nào'));
    }
}
