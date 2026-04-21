<?php

namespace Database\Factories;

use App\Models\Catalogus;
use App\Models\ZaaktypeMapping;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ZaaktypeMapping>
 */
class ZaaktypeMappingFactory extends Factory
{
    protected $model = ZaaktypeMapping::class;

    public function definition(): array
    {
        return [
            'catalogus_id' => Catalogus::factory(),
            'zaaktype_url' => 'https://openzaak.example.com/catalogi/api/v1/zaaktypen/'.fake()->uuid(),
            'zaaktype_identificatie' => strtoupper(fake()->bothify('ZT-####')),
            'zaaktype_omschrijving' => fake()->sentence(4),
            'corsa_zaaktype_code' => null,
            'is_active' => false,
            'synced_at' => now(),
        ];
    }

    public function active(): static
    {
        return $this->state([
            'corsa_zaaktype_code' => strtoupper(fake()->bothify('CODE_##')),
            'is_active' => true,
        ]);
    }

    public function withCode(string $code): static
    {
        return $this->state(['corsa_zaaktype_code' => $code]);
    }
}
