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
                    ->formatStateUsing(self::statusLabel(...))
                    ->color(self::statusColor(...))
                    ->sortable(),
                TextColumn::make('notifications_count')
                    ->label('Notificaties')
                    ->counts('notifications')
                    ->sortable(),
                self::failedNotificationsColumn(),
                self::incompleteColumn(),
                self::stuckColumn(),
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
                    ->options(self::STATUS_LABELS),
                Filter::make('failed_notifications')
                    ->label('Met mislukte notificaties')
                    ->toggle()
                    ->query(self::withFailedNotifications(...)),
                Filter::make('incomplete')
                    ->label('Onvolledig')
                    ->toggle()
                    ->query(self::incomplete(...)),
                Filter::make('stuck')
                    ->label('Lang in verwerking')
                    ->toggle()
                    ->query(self::stuck(...)),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([])
            ->defaultSort('created_at', 'desc')
            ->poll('10s');
    }

    /**
     * The statuses of a batch with their Dutch labels, for the badge and the filter.
     */
    public const STATUS_LABELS = [
        'pending' => 'Wachtend',
        'processing' => 'In verwerking',
        'processed' => 'Verwerkt',
        'failed' => 'Mislukt',
    ];

    public static function statusLabel(string $state): string
    {
        return self::STATUS_LABELS[$state] ?? $state;
    }

    public static function statusColor(string $state): string
    {
        return match ($state) {
            // Not orange, so it is not confused with "Niet alles verwerkt" and "Lang in verwerking".
            'processing' => 'info',
            'processed' => 'success',
            'failed' => 'danger',
            default => 'gray',
        };
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
     * Shown for a batch that has been processing for longer than the configured threshold without a
     * failed notification. A clock, so it is not confused with "Niet alles verwerkt".
     */
    public static function stuckColumn(): TextColumn
    {
        return TextColumn::make('stuck')
            ->label('Lang bezig')
            ->state(fn (Batch $record): ?string => $record->isStuck() ? 'Lang in verwerking' : null)
            ->badge()
            ->color('warning')
            ->icon('heroicon-o-clock')
            ->tooltip(fn (Batch $record): ?string => $record->isStuck()
                ? sprintf('De batch is al langer dan %d minuten in verwerking zonder mislukte notificatie.', (int) config('batching.stuck_after_minutes', 30))
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

    /**
     * @param  Builder<Batch>  $query
     * @return Builder<Batch>
     */
    private static function stuck(Builder $query): Builder
    {
        return $query->stuck();
    }
}
