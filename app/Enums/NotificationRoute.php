<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * How a received notification is processed.
 */
enum NotificationRoute: string implements HasColor, HasLabel
{
    /** Collected in the batch of its zaak and processed when the batch timer expires. */
    case Batch = 'batch';

    /** Processed on its own as soon as it arrives, without waiting for a batch. */
    case Direct = 'direct';

    /**
     * Readable label for the admin panel. The stored values above stay unchanged.
     */
    public function getLabel(): string
    {
        return match ($this) {
            self::Batch => 'Batch',
            self::Direct => 'Direct',
        };
    }

    /**
     * Badge colour, the same in every place the route is shown.
     */
    public function getColor(): string
    {
        return match ($this) {
            self::Batch => 'gray',
            self::Direct => 'info',
        };
    }
}
