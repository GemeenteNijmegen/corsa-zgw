<?php

namespace App\Filament\Resources\Batches\Schemas;

use Carbon\Carbon;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;

class BatchInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Batch details')
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

            Section::make('Notificaties')
                ->schema([
                    RepeatableEntry::make('notifications')
                        ->label('')
                        ->state(fn ($record): Collection => $record->notifications()
                            ->get()
                            ->sortBy(function ($notification): int {
                                $actie = $notification->notification['actie'] ?? '';
                                $resource = $notification->notification['resource'] ?? '';

                                return match ("{$actie}:{$resource}") {
                                    'create:zaak' => 0,
                                    'create:resultaat' => 2,
                                    default => 1,
                                };
                            })
                            ->values()
                        )
                        ->schema([
                            TextEntry::make('volgorde')
                                ->label('Volgorde')
                                ->state(function ($record): string {
                                    $actie = $record->notification['actie'] ?? '';
                                    $resource = $record->notification['resource'] ?? '';

                                    return match ("{$actie}:{$resource}") {
                                        'create:zaak' => '0',
                                        'create:resultaat' => '2',
                                        default => '1',
                                    };
                                }),
                            TextEntry::make('type')
                                ->label('Type')
                                ->state(fn ($record): string => sprintf(
                                    '%s:%s',
                                    $record->notification['actie'] ?? '—',
                                    $record->notification['resource'] ?? '—'
                                )),
                            TextEntry::make('aanmaakdatum')
                                ->label('Aangemaakt')
                                ->state(function ($record): ?string {
                                    $aanmaakdatum = $record->notification['aanmaakdatum'] ?? null;

                                    if ($aanmaakdatum === null) {
                                        return null;
                                    }

                                    return Carbon::parse($aanmaakdatum)->format('d-m-Y H:i:s');
                                })
                                ->placeholder('—'),
                            IconEntry::make('processed')
                                ->label('Verwerkt')
                                ->boolean(),
                            TextEntry::make('processed_at')
                                ->label('Verwerkt op')
                                ->dateTime()
                                ->placeholder('—'),
                        ])
                        ->columns(5),
                ]),
        ]);
    }
}
