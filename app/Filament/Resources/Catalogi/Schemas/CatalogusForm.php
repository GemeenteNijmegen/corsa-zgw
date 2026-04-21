<?php

namespace App\Filament\Resources\Catalogi\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class CatalogusForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('url')
                    ->label('Catalogus URL')
                    ->url()
                    ->required()
                    ->maxLength(500)
                    ->helperText('De volledige URL van de ZGW catalogus, bijv. https://openzaak.example.com/catalogi/api/v1/catalogussen/{uuid}'),
                TextInput::make('omschrijving')
                    ->label('Omschrijving')
                    ->maxLength(255),
                Toggle::make('is_active')
                    ->label('Actief')
                    ->default(true),
            ]);
    }
}
