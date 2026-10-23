<?php

namespace App\Filament\Resources\IgnoredNotifications;

use App\Filament\Resources\IgnoredNotifications\Pages\ListIgnoredNotifications;
use App\Filament\Resources\IgnoredNotifications\Tables\IgnoredNotificationsTable;
use App\Models\Notification;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only overview of notifications that were accepted but deliberately not processed.
 *
 * Ignored notifications are never added to a batch, so they do not show up in the batch overview.
 */
class IgnoredNotificationResource extends Resource
{
    protected static ?string $model = Notification::class;

    protected static ?string $slug = 'ignored-notifications';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEyeSlash;

    protected static ?string $navigationLabel = 'Genegeerde notificaties';

    protected static ?string $modelLabel = 'genegeerde notificatie';

    protected static ?string $pluralModelLabel = 'genegeerde notificaties';

    /**
     * @return Builder<Notification>
     */
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<Notification> $query */
        $query = parent::getEloquentQuery();

        return $query->ignored();
    }

    public static function table(Table $table): Table
    {
        return IgnoredNotificationsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListIgnoredNotifications::route('/'),
        ];
    }
}
