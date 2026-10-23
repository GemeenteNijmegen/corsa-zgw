<?php

namespace App\Filament\Resources\Batches;

use App\Filament\Resources\Batches\Pages\ListBatches;
use App\Filament\Resources\Batches\Pages\ViewBatch;
use App\Filament\Resources\Batches\RelationManagers\NotificationsRelationManager;
use App\Filament\Resources\Batches\Schemas\BatchInfolist;
use App\Filament\Resources\Batches\Tables\BatchesTable;
use App\Models\Batch;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class BatchResource extends Resource
{
    protected static ?string $model = Batch::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxStack;

    protected static ?string $navigationLabel = 'Notificatie-batches';

    protected static ?string $recordTitleAttribute = 'zaak_identificatie';

    /**
     * Counts the failed notifications and the notifications that are not processed yet in the same
     * query, for the overview as well as the batch page, so the signals do not query per row.
     *
     * @return Builder<Batch>
     */
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<Batch> $query */
        $query = parent::getEloquentQuery();

        return $query->withNotificationOutcome();
    }

    public static function infolist(Schema $schema): Schema
    {
        return BatchInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BatchesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            NotificationsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBatches::route('/'),
            'view' => ViewBatch::route('/{record}'),
        ];
    }
}
