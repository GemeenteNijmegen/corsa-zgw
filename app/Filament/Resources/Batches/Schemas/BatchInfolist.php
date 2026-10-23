<?php

namespace App\Filament\Resources\Batches\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class BatchInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            // Full width, so the notifications of the batch (the relation manager) follow below it as
            // a table with room for every column and the record actions.
            Section::make('Batch details')
                ->columnSpanFull()
                ->columns(2)
                ->schema([
                    TextEntry::make('zaak_identificatie')
                        ->label('Zaak'),
                    TextEntry::make('status')
                        ->label('Status')
                        ->badge()
                        ->color(fn (string $state): string => match ($state) {
                            'pending' => 'gray',
                            'processing' => 'warning',
                            'processed' => 'success',
                            default => 'gray',
                        }),
                    TextEntry::make('created_at')
                        ->label('Ontvangen')
                        ->dateTime(),
                    TextEntry::make('locked_at')
                        ->label('Vergrendeld')
                        ->dateTime()
                        ->placeholder('—'),
                    TextEntry::make('processed_at')
                        ->label('Verwerkt')
                        ->dateTime()
                        ->placeholder('—'),
                    IconEntry::make('is_locked')
                        ->label('Vergrendeld')
                        ->state(fn ($record): bool => $record->isLocked())
                        ->boolean(),
                ]),
        ]);
    }
}
