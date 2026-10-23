<?php

namespace App\Filament\Resources\IgnoredNotifications\Pages;

use App\Filament\Resources\IgnoredNotifications\IgnoredNotificationResource;
use Filament\Resources\Pages\ListRecords;

class ListIgnoredNotifications extends ListRecords
{
    protected static string $resource = IgnoredNotificationResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
