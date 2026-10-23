<?php

namespace App\Filament\Resources\Batches\Tables;

use App\Models\Batch;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class BatchesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('zaak_identificatie')
                    ->label('Zaak')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'gray',
                        // Not orange, so it is not confused with "Niet alles verwerkt".
                        'processing' => 'info',
                        'processed' => 'success',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('notifications_count')
                    ->label('Notificaties')
                    ->counts('notifications')
                    ->sortable(),
                self::failedNotificationsColumn(),
                self::incompleteColumn(),
                TextColumn::make('created_at')
                    ->label('Ontvangen')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('locked_at')
                    ->label('Vergrendeld')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('processed_at')
                    ->label('Verwerkt')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('—'),
            ])
            ->groups([
                Group::make('zaak_identificatie')
                    ->label('Zaak')
                    ->collapsible()
                    ->orderQueryUsing(fn (Builder $query, string $direction) => $query->orderByRaw(
                        // The direction is interpolated into raw SQL, so only allow known values.
                        '(select max(b2.created_at) from batches b2 where b2.zaak_identificatie = batches.zaak_identificatie) '
                            .(strtolower($direction) === 'asc' ? 'asc' : 'desc')
                    )->orderBy('zaak_identificatie')),
            ])
            ->defaultGroup('zaak_identificatie', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'pending' => 'Wachtend',
                        'processing' => 'In verwerking',
                        'processed' => 'Verwerkt',
                    ]),
                Filter::make('failed_notifications')
                    ->label('Met mislukte notificaties')
                    ->toggle()
                    ->query(self::withFailedNotifications(...)),
                Filter::make('incomplete')
                    ->label('Onvolledig')
                    ->toggle()
                    ->query(self::incomplete(...)),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([])
            ->defaultSort('created_at', 'desc')
            ->poll('10s');
    }

    /**
     * The number of notifications whose job failed for good, only when there are any. Derived from the
     * notifications; the status of the batch itself does not change.
     */
    public static function failedNotificationsColumn(): TextColumn
    {
        return TextColumn::make('failed_notifications_count')
            ->label('Mislukt')
            ->state(fn (Batch $record): ?int => $record->failedNotificationsCount() ?: null)
            ->badge()
            ->color('danger')
            ->icon('heroicon-o-x-circle')
            ->tooltip('Notificaties waarvan de job definitief is mislukt')
            ->sortable();
    }

    /**
     * Shown for a batch that is marked as processed while a notification in it is not processed, not
     * failed and not ignored. It does not say why.
     */
    public static function incompleteColumn(): TextColumn
    {
        return TextColumn::make('incomplete')
            ->label('Onvolledig')
            ->state(fn (Batch $record): ?string => $record->isIncomplete() ? 'Niet alles verwerkt' : null)
            ->badge()
            ->color('warning')
            ->icon('heroicon-o-exclamation-triangle')
            ->tooltip(fn (Batch $record): ?string => $record->isIncomplete()
                ? sprintf('De batch is afgerond, maar %d notificatie(s) erin zijn niet verwerkt, niet mislukt en niet genegeerd.', $record->notProcessedNotificationsCount())
                : null);
    }

    /**
     * @param  Builder<Batch>  $query
     * @return Builder<Batch>
     */
    private static function withFailedNotifications(Builder $query): Builder
    {
        return $query->withFailedNotifications();
    }

    /**
     * @param  Builder<Batch>  $query
     * @return Builder<Batch>
     */
    private static function incomplete(Builder $query): Builder
    {
        return $query->incomplete();
    }
}
