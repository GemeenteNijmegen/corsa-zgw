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

class BatchResource extends Resource
{
    protected static ?string $model = Batch::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxStack;

    protected static ?string $navigationLabel = 'Notificatie-batches';

    protected static ?string $recordTitleAttribute = 'zaak_identificatie';

    public static function infolist(Schema $schema): Schema
    {
        return BatchInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BatchesTable::configure($table);
    }

    public static function getRelationManagers(): array
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
