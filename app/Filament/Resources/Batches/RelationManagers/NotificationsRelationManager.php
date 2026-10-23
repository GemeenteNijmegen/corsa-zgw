<?php

namespace App\Filament\Resources\Batches\RelationManagers;

use App\Filament\Resources\Notifications\Schemas\NotificationPayloadSchema;
use App\Filament\Resources\Notifications\Tables\NotificationsTable;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class NotificationsRelationManager extends RelationManager
{
    protected static string $relationship = 'notifications';

    protected static ?string $title = 'Notificaties';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query): Builder {
                // Let the query grammar write the JSON paths, so the order also holds on PostgreSQL,
                // where the MySQL style path '$.actie' does not match.
                $grammar = $query->getQuery()->getGrammar();
                $actie = $grammar->wrap('notification->actie');
                $resource = $grammar->wrap('notification->resource');

                return $query->orderByRaw(
                    "CASE
                        WHEN {$actie} = 'create' AND {$resource} = 'zaak' THEN 0
                        WHEN {$actie} = 'create' AND {$resource} = 'resultaat' THEN 2
                        ELSE 1
                    END ASC"
                );
            })
            ->columns([
                TextColumn::make('sort_order')
                    ->label('Volgorde')
                    ->state(function ($record): string {
                        $actie = $record->notification['actie'] ?? '';
                        $resource = $record->notification['resource'] ?? '';
                        $key = "{$actie}:{$resource}";

                        return match ($key) {
                            'create:zaak' => '0',
                            'create:resultaat' => '2',
                            default => '1',
                        };
                    }),
                TextColumn::make('actie_resource')
                    ->label('Type')
                    ->state(fn ($record): string => sprintf(
                        '%s:%s',
                        $record->notification['actie'] ?? '—',
                        $record->notification['resource'] ?? '—'
                    )),
                TextColumn::make('aanmaakdatum')
                    ->label('Aangemaakt')
                    ->state(fn ($record): ?string => $record->notification['aanmaakdatum'] ?? null)
                    ->dateTime()
                    ->placeholder('—'),
                NotificationsTable::statusColumn(),
                TextColumn::make('processed_at')
                    ->label('Verwerkt op')
                    ->dateTime()
                    ->placeholder('—'),
            ])
            ->recordActions([
                NotificationPayloadSchema::action(),
                NotificationPayloadSchema::failedJobAction(),
            ])
            ->poll('10s');
    }
}
