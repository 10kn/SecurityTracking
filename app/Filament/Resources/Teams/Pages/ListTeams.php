<?php

namespace App\Filament\Resources\Teams\Pages;

use App\Filament\Resources\Teams\TeamResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTeams extends ListRecords
{
    protected static string $resource = TeamResource::class;

    public function getSubheading(): ?string
    {
        return __('Finding được gửi tới team sở hữu project bị ảnh hưởng.');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('Tạo team')),
        ];
    }
}
