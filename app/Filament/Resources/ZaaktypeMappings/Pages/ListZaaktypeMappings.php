<?php

namespace App\Filament\Resources\ZaaktypeMappings\Pages;

use App\Filament\Resources\ZaaktypeMappings\ZaaktypeMappingResource;
use Filament\Resources\Pages\ListRecords;

class ListZaaktypeMappings extends ListRecords
{
    protected static string $resource = ZaaktypeMappingResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
