<?php

namespace App\Filament\Resources\Catalogi\Pages;

use App\Filament\Resources\Catalogi\CatalogusResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCatalogus extends EditRecord
{
    protected static string $resource = CatalogusResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
