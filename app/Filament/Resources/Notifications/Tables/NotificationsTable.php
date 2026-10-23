<?php

namespace App\Filament\Resources\Notifications\Tables;

use App\Enums\NotificationRoute;
use App\Enums\NotificationStatus;
use App\Filament\Resources\Batches\BatchResource;
use App\Filament\Resources\Notifications\Schemas\NotificationPayloadSchema;
use App\Models\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class NotificationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Ontvangen')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('zaak_identificatie')
                    ->label('Zaak')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('route')
                    ->label('Route')
                    ->badge(),
                TextColumn::make('type')
                    ->label('Type')
                    ->state(fn (Notification $record): string => NotificationPayloadSchema::type($record)),
                self::statusColumn(),
                // Hidden by default to keep the actions in view: the status already says whether a
                // notification is processed, and "Bekijk payload" shows when.
                TextColumn::make('processed_at')
                    ->label('Verwerkt op')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),
                TextColumn::make('batch')
                    ->label('Batch')
                    ->state(fn (Notification $record): ?string => $record->batch_id !== null ? 'Bekijk batch' : null)
                    ->url(fn (Notification $record): ?string => $record->batch_id !== null
                        ? BatchResource::getUrl('view', ['record' => $record->batch_id])
                        : null)
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('route')
                    ->label('Route')
                    ->options(NotificationRoute::class),
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(NotificationStatus::class)
                    ->query(fn (Builder $query, array $data): Builder => self::whereStatus($query, $data['value'] ?? null)),
                SelectFilter::make('zaak_identificatie')
                    ->label('Zaak')
                    ->searchable()
                    // Search on the server, because the number of zaken grows with every notification.
                    ->getSearchResultsUsing(fn (string $search): array => Notification::query()
                        ->whereNull('ignored_at')
                        ->whereNotNull('zaak_identificatie')
                        ->whereLike('zaak_identificatie', '%'.$search.'%')
                        ->distinct()
                        ->orderBy('zaak_identificatie')
                        ->limit(50)
                        ->pluck('zaak_identificatie', 'zaak_identificatie')
                        ->all())
                    ->getOptionLabelUsing(fn (string $value): string => $value),
            ])
            ->recordActions([
                NotificationPayloadSchema::action(),
                NotificationPayloadSchema::failedJobAction(),
            ])
            ->toolbarActions([])
            ->defaultSort('created_at', 'desc')
            ->poll('10s');
    }

    /**
     * Processed, failed or not processed yet, with the failure as tooltip. Also used for the
     * notifications of a batch.
     */
    public static function statusColumn(): TextColumn
    {
        return TextColumn::make('status')
            ->label('Status')
            ->state(fn (Notification $record): NotificationStatus => $record->status())
            ->badge()
            ->tooltip(fn (Notification $record): ?string => $record->failure);
    }

    /**
     * @param  Builder<Notification>  $query
     * @return Builder<Notification>
     */
    private static function whereStatus(Builder $query, ?string $status): Builder
    {
        return match (NotificationStatus::tryFrom((string) $status)) {
            NotificationStatus::Processed => $query->where('processed', true),
            NotificationStatus::Failed => $query->where('processed', false)->whereNotNull('failed_at'),
            NotificationStatus::Pending => $query->where('processed', false)->whereNull('failed_at'),
            null => $query,
        };
    }
}
