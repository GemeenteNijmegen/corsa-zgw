<?php

namespace App\Filament\Resources\Batches\Schemas;

use App\Filament\Resources\Batches\Tables\BatchesTable;
use App\Models\Batch;
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
                        ->formatStateUsing(BatchesTable::statusLabel(...))
                        ->color(BatchesTable::statusColor(...)),
                    TextEntry::make('created_at')
                        ->label('Ontvangen')
                        ->dateTime(),
                    TextEntry::make('locked_at')
                        ->label('Vergrendeld op')
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
                    // Derived from the notifications, the same signals as in the batches overview.
                    TextEntry::make('failed_notifications')
                        ->label('Mislukte notificaties')
                        ->state(fn (Batch $record): int => $record->failedNotificationsCount())
                        ->badge()
                        ->color('danger')
                        ->icon('heroicon-o-x-circle')
                        ->visible(fn (Batch $record): bool => $record->failedNotificationsCount() > 0),
                    TextEntry::make('incomplete')
                        ->label('Onvolledig')
                        ->state('Niet alles verwerkt')
                        ->badge()
                        ->color('warning')
                        ->icon('heroicon-o-exclamation-triangle')
                        ->helperText(fn (Batch $record): string => sprintf(
                            'De batch is afgerond, maar %d notificatie(s) erin zijn niet verwerkt, niet mislukt en niet genegeerd.',
                            $record->notProcessedNotificationsCount(),
                        ))
                        ->visible(fn (Batch $record): bool => $record->isIncomplete()),
                    TextEntry::make('stuck')
                        ->label('Lang bezig')
                        ->state('Lang in verwerking')
                        ->badge()
                        ->color('warning')
                        ->icon('heroicon-o-clock')
                        ->helperText(fn (): string => sprintf(
                            'De batch is al langer dan %d minuten in verwerking zonder mislukte notificatie.',
                            (int) config('batching.stuck_after_minutes', 30),
                        ))
                        ->visible(fn (Batch $record): bool => $record->isStuck()),
                ]),
        ]);
    }
}
