<?php

namespace App\Filament\Resources\ZaaktypeMappings\Tables;

use App\Jobs\Sync\SyncAllCatalogi;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ZaaktypeMappingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('zaaktype_omschrijving')
                    ->label('Omschrijving')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('zaaktype_identificatie')
                    ->label('Identificatie')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('catalogus.omschrijving')
                    ->label('Catalogus')
                    ->sortable(),
                TextColumn::make('corsa_zaaktype_code')
                    ->label('Corsa code')
                    ->placeholder('Niet ingesteld'),
                IconColumn::make('is_active')
                    ->label('Actief')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('synced_at')
                    ->label('Gesynchroniseerd')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('catalogus')
                    ->relationship('catalogus', 'omschrijving')
                    ->label('Catalogus'),
                TernaryFilter::make('is_active')
                    ->label('Actief'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([]),
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
            ])
            ->poll('10s');
    }
}
