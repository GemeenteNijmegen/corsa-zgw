<?php

namespace App\Filament\Resources\ZaaktypeMappings\Pages;

use App\Filament\Resources\ZaaktypeMappings\ZaaktypeMappingResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditZaaktypeMapping extends EditRecord
{
    protected static string $resource = ZaaktypeMappingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
