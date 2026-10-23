<?php

namespace App\Filament\Resources\Batches\Pages;

use App\Filament\Resources\Batches\BatchResource;
use App\Filament\Resources\IgnoredNotifications\IgnoredNotificationResource;
use App\Models\Batch;
use App\Models\Notification;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewBatch extends ViewRecord
{
    protected static string $resource = BatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('ignored_notifications')
                ->label('Genegeerde notificaties van deze zaak')
                ->icon('heroicon-o-eye-slash')
                ->color('gray')
                ->url(fn (Batch $record): string => IgnoredNotificationResource::getUrl('index', [
                    'filters' => [
                        'zaak_identificatie' => ['value' => $record->zaak_identificatie],
                    ],
                ]))
                ->visible(fn (Batch $record): bool => Notification::query()
                    ->ignored()
                    ->where('zaak_identificatie', $record->zaak_identificatie)
                    ->exists()),
        ];
    }
}
