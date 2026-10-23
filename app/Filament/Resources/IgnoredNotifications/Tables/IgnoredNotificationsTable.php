<?php

namespace App\Filament\Resources\IgnoredNotifications\Tables;

use App\Enums\NotificationIgnoreReason;
use App\Models\Notification;
use Filament\Actions\Action;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Tables\Columns\Summarizers\Count;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;

class IgnoredNotificationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                // A notification is ignored when it is received, so the time it was received is
                // only shown in "Bekijk payload", and only when it differs.
                TextColumn::make('ignored_at')
                    ->label('Genegeerd op')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('zaak_identificatie')
                    ->label('Zaak')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('ignored_reason')
                    ->label('Reden')
                    ->badge()
                    ->color(fn (NotificationIgnoreReason $state): string => match ($state) {
                        NotificationIgnoreReason::UnknownZaaktype,
                        NotificationIgnoreReason::InactiveZaaktype => 'warning',
                        default => 'gray',
                    })
                    // The count counts the non-null values of its column, so it is placed on a
                    // column that is always filled for an ignored notification. On the zaak column
                    // the group without a zaak would count 0, and on the first column the summary
                    // is not rendered.
                    ->summarize(Count::make()->label('Aantal')),
                TextColumn::make('kanaal')
                    ->label('Kanaal')
                    ->state(fn (Notification $record): ?string => $record->notification['kanaal'] ?? null)
                    ->placeholder('—'),
                TextColumn::make('actie')
                    ->label('Actie')
                    ->state(fn (Notification $record): ?string => $record->notification['actie'] ?? null)
                    ->placeholder('—'),
                TextColumn::make('resource')
                    ->label('Resource')
                    ->state(fn (Notification $record): ?string => $record->notification['resource'] ?? null)
                    ->placeholder('—'),
            ])
            ->groups([
                Group::make('zaak_identificatie')
                    ->label('Zaak')
                    ->collapsible()
                    ->getTitleFromRecordUsing(fn (Notification $record): string => $record->zaak_identificatie ?? 'Geen zaak'),
            ])
            ->filters([
                SelectFilter::make('ignored_reason')
                    ->label('Reden')
                    ->options(NotificationIgnoreReason::class),
                SelectFilter::make('zaak_identificatie')
                    ->label('Zaak')
                    ->searchable()
                    // Search on the server, because the number of zaken grows with every ignored notification.
                    ->getSearchResultsUsing(fn (string $search): array => Notification::query()
                        ->ignored()
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
                                TextEntry::make('ignored_reason')
                                    ->label('Reden')
                                    ->badge(),
                                TextEntry::make('kanaal')
                                    ->label('Kanaal')
                                    ->state(fn (Notification $record): ?string => $record->notification['kanaal'] ?? null)
                                    ->placeholder('—'),
                                TextEntry::make('type')
                                    ->label('Type')
                                    ->state(fn (Notification $record): string => sprintf(
                                        '%s:%s',
                                        $record->notification['actie'] ?? '—',
                                        $record->notification['resource'] ?? '—'
                                    )),
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
                                    ->state(fn (Notification $record): ?string => $record->notification['aanmaakdatum'] ?? null)
                                    ->dateTime()
                                    ->placeholder('—'),
                                TextEntry::make('ignored_at')
                                    ->label('Genegeerd op')
                                    ->dateTime(),
                                TextEntry::make('created_at')
                                    ->label('Ontvangen')
                                    ->dateTime()
                                    // Only notifications that were in a batch before they were ignored
                                    // differ; the margin covers a second boundary between the two writes.
                                    ->visible(fn (Notification $record): bool => $record->created_at !== null
                                        && $record->ignored_at !== null
                                        && abs($record->created_at->diffInSeconds($record->ignored_at)) > 1),
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
                                        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                                    ))
                                    ->fontFamily('mono')
                                    ->columnSpanFull(),
                            ]),
                    ]),
            ])
            ->toolbarActions([])
            ->defaultSort('ignored_at', 'desc')
            ->poll('10s');
    }
}
