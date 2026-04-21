<?php

namespace App\Filament\Resources\Catalogi\Pages;

use App\Filament\Resources\Catalogi\CatalogusResource;
use App\Jobs\Sync\SyncAllCatalogi;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListCatalogi extends ListRecords
{
    protected static string $resource = CatalogusResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('syncAll')
                ->label('Sync alle catalogi')
                ->icon('heroicon-o-arrow-path')
                ->action(function () {
                    SyncAllCatalogi::dispatch();

                    Notification::make()
                        ->title('Sync gestart')
                        ->body('Alle catalogi worden op de achtergrond gesynchroniseerd.')
                        ->success()
                        ->send();
                })
                ->color('gray'),
            CreateAction::make(),
        ];
    }
}
