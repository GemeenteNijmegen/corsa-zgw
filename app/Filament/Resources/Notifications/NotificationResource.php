<?php

namespace App\Filament\Resources\Notifications;

use App\Filament\Resources\Notifications\Pages\ListNotifications;
use App\Filament\Resources\Notifications\Tables\NotificationsTable;
use App\Models\Notification;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only overview of the notifications that are processed, on the batch route and on the direct route.
 *
 * Notifications on the direct route are never added to a batch, so this is where they show up.
 * Ignored notifications have their own overview.
 */
class NotificationResource extends Resource
{
    protected static ?string $model = Notification::class;

    protected static ?string $slug = 'notifications';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBell;

    protected static ?string $navigationLabel = 'Notificaties';

    protected static ?string $modelLabel = 'notificatie';

    protected static ?string $pluralModelLabel = 'notificaties';

    /**
     * @return Builder<Notification>
     */
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<Notification> $query */
        $query = parent::getEloquentQuery();

        return $query->whereNull('ignored_at');
    }

    public static function table(Table $table): Table
    {
        return NotificationsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNotifications::route('/'),
        ];
    }
}
