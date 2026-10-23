<?php

namespace App\Filament\Resources\IgnoredNotifications\Pages;

use App\Filament\Resources\IgnoredNotifications\IgnoredNotificationResource;
use Filament\Resources\Pages\ListRecords;

class ListIgnoredNotifications extends ListRecords
{
    protected static string $resource = IgnoredNotificationResource::class;

    // Same capitalisation as the navigation label, instead of Filament's title case.
    protected static ?string $title = 'Genegeerde notificaties';

    protected function getHeaderActions(): array
    {
        return [];
    }
}
