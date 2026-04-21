<?php

namespace Database\Factories;

use App\Models\Batch;
use App\Models\Notification;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Notification>
 */
class NotificationFactory extends Factory
{
    protected $model = Notification::class;

    public function definition(): array
    {
        $zaakIdentificatie = 'ZAAK-'.fake()->year().'-'.fake()->numberBetween(1000, 9999);
        $actie = 'create';
        $resource = fake()->randomElement(['zaak', 'status', 'zaakinformatieobject', 'resultaat']);

        return [
            'batch_id' => Batch::factory(),
            'zaak_identificatie' => $zaakIdentificatie,
            'notification' => [
                'actie' => $actie,
                'kanaal' => 'zaken',
                'resource' => $resource,
                'hoofdObject' => 'https://openzaak.example.com/zaken/api/v1/zaken/'.fake()->uuid(),
                'resourceUrl' => 'https://openzaak.example.com/zaken/api/v1/'.$resource.'en/'.fake()->uuid(),
                'aanmaakdatum' => now()->toIso8601String(),
            ],
            'processed' => false,
            'processed_at' => null,
        ];
    }

    public function forZaak(): static
    {
        return $this->state([
            'notification' => [
                'actie' => 'create',
                'kanaal' => 'zaken',
                'resource' => 'zaak',
                'hoofdObject' => 'https://openzaak.example.com/zaken/api/v1/zaken/'.fake()->uuid(),
                'resourceUrl' => 'https://openzaak.example.com/zaken/api/v1/zaken/'.fake()->uuid(),
                'aanmaakdatum' => now()->toIso8601String(),
            ],
        ]);
    }

    public function forResultaat(): static
    {
        return $this->state([
            'notification' => [
                'actie' => 'create',
                'kanaal' => 'zaken',
                'resource' => 'resultaat',
                'hoofdObject' => 'https://openzaak.example.com/zaken/api/v1/zaken/'.fake()->uuid(),
                'resourceUrl' => 'https://openzaak.example.com/zaken/api/v1/resultaten/'.fake()->uuid(),
                'aanmaakdatum' => now()->toIso8601String(),
            ],
        ]);
    }

    public function processed(): static
    {
        return $this->state([
            'processed' => true,
            'processed_at' => now(),
        ]);
    }
}
