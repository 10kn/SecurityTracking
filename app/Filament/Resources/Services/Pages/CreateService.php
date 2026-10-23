<?php

namespace App\Filament\Resources\Services\Pages;

use App\Filament\Resources\Services\ServiceResource;
use App\Models\Service;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\DB;

class CreateService extends CreateRecord
{
    use ManagesProductionInventory;

    protected static string $resource = ServiceResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Service
    {
        return DB::transaction(function () use ($data): Service {
            $service = Service::create($this->serviceAttributes($data));

            $this->saveProductionInventory($service, $data);

            return $service;
        });
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
