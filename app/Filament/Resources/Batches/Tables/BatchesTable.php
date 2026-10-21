<?php

namespace App\Filament\Resources\Batches\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
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
                        'processing' => 'warning',
                        'processed' => 'success',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('notifications_count')
                    ->label('Notificaties')
                    ->counts('notifications')
                    ->sortable(),
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
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([])
            ->defaultSort('created_at', 'desc')
            ->poll('10s');
    }
}
