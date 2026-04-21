<?php

namespace App\Filament\Resources\Catalogi\Tables;

use App\Jobs\Sync\SyncCatalogus;
use App\Models\Catalogus;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CatalogiTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('omschrijving')
                    ->label('Omschrijving')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('url')
                    ->label('Catalogus URL')
                    ->limit(60)
                    ->searchable(),
                IconColumn::make('is_active')
                    ->label('Actief')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('zaaktypeMappings_count')
                    ->label('Zaaktypes')
                    ->counts('zaaktypeMappings')
                    ->sortable(),
                TextColumn::make('last_synced_at')
                    ->label('Laatste sync')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('Nooit'),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                Action::make('sync')
                    ->label('Sync')
                    ->icon('heroicon-o-arrow-path')
                    ->action(function (Catalogus $record) {
                        SyncCatalogus::dispatch($record);

                        Notification::make()
                            ->title('Sync gestart')
                            ->body("Zaaktypes voor \"{$record->omschrijving}\" worden op de achtergrond gesynchroniseerd.")
                            ->success()
                            ->send();
                    }),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->poll('10s');
    }
}
