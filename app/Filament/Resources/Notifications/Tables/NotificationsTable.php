<?php

namespace App\Filament\Resources\Notifications\Tables;

use App\Enums\NotificationRoute;
use App\Filament\Resources\Batches\BatchResource;
use App\Models\Notification;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

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
                    ->state(fn (Notification $record): string => self::type($record)),
                IconColumn::make('processed')
                    ->label('Verwerkt')
                    ->boolean(),
                TextColumn::make('processed_at')
                    ->label('Verwerkt op')
                    ->dateTime()
                    ->sortable()
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
                TernaryFilter::make('processed')
                    ->label('Verwerkt')
                    ->trueLabel('Verwerkt')
                    ->falseLabel('Niet verwerkt'),
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
                Action::make('view_payload')
                    ->label('Bekijk payload')
                    ->icon('heroicon-o-eye')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Sluiten')
                    ->schema([
                        Section::make('Notificatie')
                            ->columns(2)
                            ->schema([
                                TextEntry::make('zaak_identificatie')
                                    ->label('Zaak')
                                    ->placeholder('—'),
                                TextEntry::make('route')
                                    ->label('Route')
                                    ->badge(),
                                TextEntry::make('type')
                                    ->label('Type')
                                    ->state(fn (Notification $record): string => self::type($record)),
                                IconEntry::make('processed')
                                    ->label('Verwerkt')
                                    ->boolean(),
                                TextEntry::make('hoofdObject')
                                    ->label('Hoofdobject')
                                    ->state(fn (Notification $record): ?string => $record->notification['hoofdObject'] ?? null)
                                    ->placeholder('—')
                                    ->columnSpanFull(),
                                TextEntry::make('resourceUrl')
                                    ->label('Resource-URL')
                                    ->state(fn (Notification $record): ?string => $record->notification['resourceUrl'] ?? null)
                                    ->placeholder('—')
                                    ->columnSpanFull(),
                                TextEntry::make('aanmaakdatum')
                                    ->label('Aangemaakt')
                                    ->state(function (Notification $record): ?string {
                                        $aanmaakdatum = $record->notification['aanmaakdatum'] ?? null;

                                        if ($aanmaakdatum === null) {
                                            return null;
                                        }

                                        return Carbon::parse($aanmaakdatum)->format('d-m-Y H:i:s');
                                    })
                                    ->placeholder('—'),
                                TextEntry::make('processed_at')
                                    ->label('Verwerkt op')
                                    ->dateTime()
                                    ->placeholder('—'),
                                KeyValueEntry::make('kenmerken')
                                    ->label('Kenmerken')
                                    ->keyLabel('Kenmerk')
                                    ->valueLabel('Waarde')
                                    ->state(fn (Notification $record): array => $record->notification['kenmerken'] ?? [])
                                    ->placeholder('Geen kenmerken')
                                    ->columnSpanFull(),
                            ]),
                        Section::make('Notificatie payload')
                            ->schema([
                                TextEntry::make('notification')
                                    ->label('JSON payload')
                                    ->state(fn (Notification $record): string => json_encode(
                                        $record->notification,
                                        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                                    ))
                                    ->fontFamily('mono')
                                    ->columnSpanFull(),
                            ]),
                    ]),
            ])
            ->toolbarActions([])
            ->defaultSort('created_at', 'desc')
            ->poll('10s');
    }

    private static function type(Notification $record): string
    {
        return sprintf(
            '%s:%s',
            $record->notification['actie'] ?? '—',
            $record->notification['resource'] ?? '—'
        );
    }
}
