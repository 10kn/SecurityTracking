<?php

namespace App\Filament\Resources\Services\Pages;

use App\Actions\UpdateProductionInventory;
use App\Models\Service;
use Illuminate\Support\Arr;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * The production fields of the service form are not service columns. These helpers move them out of the form data
 * and hand them to UpdateProductionInventory.
 */
trait ManagesProductionInventory
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function serviceAttributes(array $data): array
    {
        return Arr::except($data, ['runtimes', 'lockfile_uploads', 'removed_lockfiles']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function saveProductionInventory(Service $service, array $data): void
    {
        $uploadedLockfiles = array_values(array_map(
            fn (TemporaryUploadedFile $file): array => ['name' => $file->getClientOriginalName(), 'content' => (string) $file->get()],
            array_filter(Arr::wrap($data['lockfile_uploads'] ?? []), fn (mixed $file): bool => $file instanceof TemporaryUploadedFile),
        ));

        app(UpdateProductionInventory::class)->handle(
            $service,
            Arr::pluck(Arr::wrap($data['runtimes'] ?? []), 'raw_version', 'technology_id'),
            $uploadedLockfiles,
            array_values($data['removed_lockfiles'] ?? []),
        );
    }
}
