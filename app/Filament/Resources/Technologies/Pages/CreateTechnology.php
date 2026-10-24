<?php

namespace App\Filament\Resources\Technologies\Pages;

use App\Filament\Resources\Technologies\TechnologyResource;
use App\Jobs\SyncTechnologyCatalogJob;
use App\Models\Technology;
use Filament\Resources\Pages\CreateRecord;

/**
 * @property Technology $record
 */
class CreateTechnology extends CreateRecord
{
    protected static string $resource = TechnologyResource::class;

    /**
     * The first sync runs right away so the version list is ready when the technology is picked on a service.
     */
    protected function afterCreate(): void
    {
        SyncTechnologyCatalogJob::dispatch($this->record);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
