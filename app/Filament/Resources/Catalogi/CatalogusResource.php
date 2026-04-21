<?php

namespace App\Filament\Resources\Catalogi;

use App\Filament\Resources\Catalogi\Pages\CreateCatalogus;
use App\Filament\Resources\Catalogi\Pages\EditCatalogus;
use App\Filament\Resources\Catalogi\Pages\ListCatalogi;
use App\Filament\Resources\Catalogi\Schemas\CatalogusForm;
use App\Filament\Resources\Catalogi\Tables\CatalogiTable;
use App\Models\Catalogus;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class CatalogusResource extends Resource
{
    protected static ?string $model = Catalogus::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;

    protected static ?string $navigationLabel = 'Catalogi';

    protected static ?string $recordTitleAttribute = 'omschrijving';

    public static function form(Schema $schema): Schema
    {
        return CatalogusForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CatalogiTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCatalogi::route('/'),
            'create' => CreateCatalogus::route('/create'),
            'edit' => EditCatalogus::route('/{record}/edit'),
        ];
    }
}
