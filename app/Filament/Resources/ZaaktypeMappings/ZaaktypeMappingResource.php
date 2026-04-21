<?php

namespace App\Filament\Resources\ZaaktypeMappings;

use App\Filament\Resources\ZaaktypeMappings\Pages\EditZaaktypeMapping;
use App\Filament\Resources\ZaaktypeMappings\Pages\ListZaaktypeMappings;
use App\Filament\Resources\ZaaktypeMappings\Schemas\ZaaktypeMappingForm;
use App\Filament\Resources\ZaaktypeMappings\Tables\ZaaktypeMappingsTable;
use App\Models\ZaaktypeMapping;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ZaaktypeMappingResource extends Resource
{
    protected static ?string $model = ZaaktypeMapping::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static ?string $navigationLabel = 'Zaaktype mappings';

    protected static ?string $recordTitleAttribute = 'zaaktype_omschrijving';

    public static function form(Schema $schema): Schema
    {
        return ZaaktypeMappingForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ZaaktypeMappingsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListZaaktypeMappings::route('/'),
            'edit' => EditZaaktypeMapping::route('/{record}/edit'),
        ];
    }
}
