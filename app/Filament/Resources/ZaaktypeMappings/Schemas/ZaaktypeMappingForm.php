<?php

namespace App\Filament\Resources\ZaaktypeMappings\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ZaaktypeMappingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('corsa_zaaktype_code')
                    ->label('Corsa zaaktype code')
                    ->maxLength(20)
                    ->regex('/^[A-Za-z0-9_]+$/')
                    ->helperText('Maximaal 20 tekens, letters, cijfers en underscores'),
                Toggle::make('is_active')
                    ->label('Actief')
                    ->helperText('Alleen actieve zaaktypes worden verwerkt'),
            ]);
    }
}
