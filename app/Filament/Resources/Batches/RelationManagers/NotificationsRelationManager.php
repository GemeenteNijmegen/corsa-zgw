<?php

namespace App\Filament\Resources\Batches\RelationManagers;

use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Tables\Columns\IconColumn;
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
            ->modifyQueryUsing(fn (Builder $query) => $query->orderByRaw(
                "CASE
                    WHEN notification->>'$.actie' = 'create' AND notification->>'$.resource' = 'zaak' THEN 0
                    WHEN notification->>'$.actie' = 'create' AND notification->>'$.resource' = 'resultaat' THEN 2
                    ELSE 1
                END ASC"
            ))
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
                    ->state(function ($record): ?string {
                        $aanmaakdatum = $record->notification['aanmaakdatum'] ?? null;

                        if ($aanmaakdatum === null) {
                            return null;
                        }

                        return Carbon::parse($aanmaakdatum)->format('d-m-Y H:i:s');
                    })
                    ->placeholder('—'),
                IconColumn::make('processed')
                    ->label('Verwerkt')
                    ->boolean(),
                TextColumn::make('processed_at')
                    ->label('Verwerkt op')
                    ->dateTime()
                    ->placeholder('—'),
            ])
            ->recordActions([
                Action::make('view_payload')
                    ->label('Bekijk payload')
                    ->icon('heroicon-o-eye')
                    ->infolist([
                        Section::make('Notificatie payload')
                            ->schema([
                                TextEntry::make('notification')
                                    ->label('JSON payload')
                                    ->state(fn ($record): string => json_encode(
                                        $record->notification,
                                        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
                                    ))
                                    ->fontFamily('mono')
                                    ->columnSpanFull(),
                            ]),
                    ]),
            ])
            ->poll('10s');
    }
}
