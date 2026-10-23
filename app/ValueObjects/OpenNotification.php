<?php

namespace App\ValueObjects;

use Illuminate\Contracts\Support\Arrayable;

final readonly class OpenNotification implements Arrayable
{
    /**
     * The kanalen this application processes.
     *
     * @var list<string>
     */
    public const SUPPORTED_KANALEN = ['zaken', 'documenten'];

    /**
     * The actie:resource combinations on the supported kanalen that this application processes.
     * The resource names differ per kanaal, so one list covers both.
     *
     * @var list<string>
     */
    public const SUPPORTED_ACTIONS = [
        'create:zaak',
        'create:status',
        'create:zaakinformatieobject',
        'create:resultaat',
        ...self::DOCUMENT_UPDATE_ACTIONS,
    ];

    /**
     * The changes to a document (PUT and PATCH) that are sent to the document management system.
     *
     * @var list<string>
     */
    public const DOCUMENT_UPDATE_ACTIONS = [
        'update:enkelvoudiginformatieobject',
        'partial_update:enkelvoudiginformatieobject',
    ];

    /** @var array<string, string|null> */
    public array $kenmerken;

    /**
     * @param  array<string, string|null>|null  $kenmerken
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
        return in_array($this->kanaal, self::SUPPORTED_KANALEN, true);
    }

    /**
     * A change to a document. Its hoofdObject is the document, not a zaak.
     */
    public function isDocumentUpdate(): bool
    {
        return self::isDocumentUpdateAction($this->actie, $this->resource);
    }

    public static function isDocumentUpdateAction(?string $actie, ?string $resource): bool
    {
        return in_array("{$actie}:{$resource}", self::DOCUMENT_UPDATE_ACTIONS, true);
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
