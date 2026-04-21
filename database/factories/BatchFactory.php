<?php

namespace Database\Factories;

use App\Models\Batch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Batch>
 */
class BatchFactory extends Factory
{
    protected $model = Batch::class;

    public function definition(): array
    {
        return [
            'zaak_identificatie' => 'ZAAK-'.fake()->year().'-'.fake()->numberBetween(1000, 9999),
            'status' => 'pending',
            'locked_at' => null,
            'processed_at' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state([
            'status' => 'pending',
            'locked_at' => null,
            'processed_at' => null,
        ]);
    }

    public function processing(): static
    {
        return $this->state([
            'status' => 'processing',
            'locked_at' => now(),
            'processed_at' => null,
        ]);
    }

    public function processed(): static
    {
        return $this->state([
            'status' => 'processed',
            'locked_at' => now()->subMinute(),
            'processed_at' => now(),
        ]);
    }
}
