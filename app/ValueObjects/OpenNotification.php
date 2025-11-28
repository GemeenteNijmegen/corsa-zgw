<?php

namespace App\ValueObjects;

use Illuminate\Contracts\Support\Arrayable;

final readonly class OpenNotification implements Arrayable
{
    public function __construct(
        public string $actie,
        public string $kanaal,
        public string $resource,
        public string $hoofdObject,
        public string $resourceUrl,
        public string $aanmaakdatum,
    ) {}

    public function toArray(): array
    {
        return [
            'actie' => $this->actie,
            'kanaal' => $this->kanaal,
            'resource' => $this->resource,
            'hoofdObject' => $this->hoofdObject,
            'resourceUrl' => $this->resourceUrl,
            'aanmaakdatum' => $this->aanmaakdatum,
        ];
    }
}
