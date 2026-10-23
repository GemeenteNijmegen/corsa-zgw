<?php

namespace App\Filament\Resources\Batches\RelationManagers;

use App\Filament\Resources\Notifications\Schemas\NotificationPayloadSchema;
use App\Filament\Resources\Notifications\Tables\NotificationsTable;
use App\Models\Notification;
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
            ->modifyQueryUsing(self::inProcessingOrder(...))
            ->columns([
                TextColumn::make('sort_order')
                    ->label('Volgorde')
                    ->state(fn ($record): string => (string) Notification::processingGroup(
                        $record->notification['actie'] ?? null,
                        $record->notification['resource'] ?? null,
                    )),
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

    /**
     * The order in which the batch processes its notifications.
     *
     * @param  Builder<Notification>  $query
     * @return Builder<Notification>
     */
    private static function inProcessingOrder(Builder $query): Builder
    {
        return $query->inProcessingOrder();
    }
}
