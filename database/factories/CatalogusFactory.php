<?php

namespace Database\Factories;

use App\Models\Catalogus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Catalogus>
 */
class CatalogusFactory extends Factory
{
    protected $model = Catalogus::class;

    public function definition(): array
    {
        return [
            'url' => 'https://openzaak.example.com/catalogi/api/v1/catalogussen/'.fake()->uuid(),
            'omschrijving' => fake()->words(3, true),
            'is_active' => true,
            'last_synced_at' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function synced(): static
    {
        return $this->state(['last_synced_at' => now()]);
    }
}
