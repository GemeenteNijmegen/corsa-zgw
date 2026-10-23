<?php

namespace Database\Factories;

use App\Models\DocumentSync;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentSync>
 */
class DocumentSyncFactory extends Factory
{
    protected $model = DocumentSync::class;

    public function definition(): array
    {
        return [
            'document_url' => 'https://openzaak.example.com/documenten/api/v1/enkelvoudiginformatieobjecten/'.fake()->uuid(),
            'zaak_identificatie' => 'ZAAK-'.fake()->year().'-'.fake()->numberBetween(1000, 9999),
            'corsa_identificatie' => 'DOC-'.fake()->numberBetween(1000, 9999),
            'versie' => 1,
            'placeholder' => false,
            'synced_at' => now(),
        ];
    }
}
