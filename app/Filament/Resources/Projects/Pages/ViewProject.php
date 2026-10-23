<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Services\ServiceResource;
use App\Models\Project;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

/**
 * @property Project $record
 */
class ViewProject extends ViewRecord
{
    protected static string $resource = ProjectResource::class;

    public function getTitle(): string
    {
        return $this->record->name;
    }

    public function getSubheading(): ?string
    {
        return __('Team :team · :count service', [
            'team' => $this->record->team->name,
            'count' => $this->record->services()->count(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->label(__('Sửa'))->color('gray'),
            Action::make('createService')
                ->label(__('Thêm service'))
                ->url(fn (): string => ServiceResource::getUrl('create', ['project' => $this->record->getKey()])),
        ];
    }
}
