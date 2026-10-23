<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Where a notification that is not ignored stands, as shown in the admin panel.
 */
enum NotificationStatus: string implements HasColor, HasIcon, HasLabel
{
    case Processed = 'processed';

    /** The job that processed it failed for good, after its last attempt. */
    case Failed = 'failed';

    /**
     * Not processed and no failure recorded: still queued, waiting in a batch, or held up by a
     * job before it in the batch chain that failed.
     */
    case Pending = 'pending';

    public function getLabel(): string
    {
        return match ($this) {
            self::Processed => 'Verwerkt',
            self::Failed => 'Mislukt',
            self::Pending => 'Nog niet verwerkt',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Processed => 'success',
            self::Failed => 'danger',
            self::Pending => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Processed => 'heroicon-o-check-circle',
            self::Failed => 'heroicon-o-x-circle',
            self::Pending => 'heroicon-o-clock',
        };
    }
}
