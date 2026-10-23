<?php

namespace App\ValueObjects;

use Illuminate\Contracts\Support\Arrayable;

final readonly class OpenNotification implements Arrayable
{
    /** The kanaal this application processes. */
    public const SUPPORTED_KANAAL = 'zaken';

    /**
     * The actie:resource combinations on the supported kanaal that this application processes.
     *
     * @var list<string>
     */
    public const SUPPORTED_ACTIONS = [
        'create:zaak',
        'create:status',
        'create:zaakinformatieobject',
        'create:resultaat',
    ];

    /** @var array<string, mixed> */
    public array $kenmerken;

    /**
     * @param  array<string, mixed>|null  $kenmerken
     */
    public function __construct(
        public string $actie,
        public string $kanaal,
        public string $resource,
        public string $hoofdObject,
        public string $resourceUrl,
        public string $aanmaakdatum,
        ?array $kenmerken = null,
    ) {
        $this->kenmerken = $kenmerken ?? [];
    }

    public function isSupportedKanaal(): bool
    {
        return $this->kanaal === self::SUPPORTED_KANAAL;
    }

    public function isSupportedAction(): bool
    {
        return in_array("{$this->actie}:{$this->resource}", self::SUPPORTED_ACTIONS, true);
    }

    public function toArray(): array
    {
        return [
            'actie' => $this->actie,
            'kanaal' => $this->kanaal,
            'resource' => $this->resource,
            'hoofdObject' => $this->hoofdObject,
            'resourceUrl' => $this->resourceUrl,
            'aanmaakdatum' => $this->aanmaakdatum,
            // A job queued before kenmerken existed is unserialized without this property.
            'kenmerken' => $this->kenmerken ?? [], // @phpstan-ignore nullCoalesce.initializedProperty
        ];
    }
}
