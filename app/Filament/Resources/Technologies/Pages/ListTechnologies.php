<?php

namespace App\Filament\Resources\Technologies\Pages;

use App\Filament\Resources\Technologies\TechnologyResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTechnologies extends ListRecords
{
    protected static string $resource = TechnologyResource::class;

    public function getSubheading(): ?string
    {
        return __('Công nghệ chọn được khi nhập version cho service. Danh mục version tự cập nhật mỗi giờ.');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('Thêm công nghệ')),
        ];
    }
}
