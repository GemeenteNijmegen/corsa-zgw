<?php

namespace App\Filament\Resources\Notifications\Schemas;

use App\Enums\NotificationStatus;
use App\Models\Notification;
use Filament\Actions\Action;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;

/**
 * "Bekijk payload" for a notification that is not ignored: in the notifications overview and in
 * the notifications of a batch.
 */
class NotificationPayloadSchema
{
    /**
     * The record action that opens the payload.
     */
    public static function action(): Action
    {
        return Action::make('view_payload')
            ->label('Bekijk payload')
            ->icon('heroicon-o-eye')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Sluiten')
            ->schema(self::components());
    }

    /**
     * The record action that opens the failed job in the job monitor, when its id is known.
     */
    public static function failedJobAction(): Action
    {
        return Action::make('failed_job')
            ->label('Mislukte job')
            ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
            ->color('danger')
            ->url(fn (Notification $record): ?string => $record->failedJobUrl())
            ->openUrlInNewTab()
            ->visible(fn (Notification $record): bool => $record->failedJobUrl() !== null);
    }

    /**
     * @return array<int, Section>
     */
    public static function components(): array
    {
        return [
            Section::make('Notificatie')
                ->columns(2)
                ->schema([
                    TextEntry::make('zaak_identificatie')
                        ->label('Zaak')
                        ->placeholder('—'),
                    TextEntry::make('route')
                        ->label('Route')
                        ->badge(),
                    TextEntry::make('type')
                        ->label('Type')
                        ->state(fn (Notification $record): string => self::type($record)),
                    TextEntry::make('status')
                        ->label('Status')
                        ->state(fn (Notification $record): NotificationStatus => $record->status())
                        ->badge(),
                    TextEntry::make('hoofdObject')
                        ->label('Hoofdobject')
                        ->state(fn (Notification $record): ?string => $record->notification['hoofdObject'] ?? null)
                        ->placeholder('—')
                        ->columnSpanFull(),
                    TextEntry::make('resourceUrl')
                        ->label('Resource-URL')
                        ->state(fn (Notification $record): ?string => $record->notification['resourceUrl'] ?? null)
                        ->placeholder('—')
                        ->columnSpanFull(),
                    TextEntry::make('aanmaakdatum')
                        ->label('Aangemaakt')
                        ->state(fn (Notification $record): ?string => $record->notification['aanmaakdatum'] ?? null)
                        ->dateTime()
                        ->placeholder('—'),
                    TextEntry::make('created_at')
                        ->label('Ontvangen')
                        ->dateTime(),
                    TextEntry::make('processed_at')
                        ->label('Verwerkt op')
                        ->dateTime()
                        ->placeholder('—'),
                    TextEntry::make('failed_at')
                        ->label('Mislukt op')
                        ->dateTime()
                        ->visible(fn (Notification $record): bool => $record->failed_at !== null),
                    TextEntry::make('failure')
                        ->label('Fout')
                        ->visible(fn (Notification $record): bool => $record->failure !== null)
                        ->columnSpanFull(),
                    TextEntry::make('failed_job_id')
                        ->label('Mislukte job')
                        ->state('Openen in de job monitor')
                        ->url(fn (Notification $record): ?string => $record->failedJobUrl())
                        ->openUrlInNewTab()
                        ->color('danger')
                        // The job monitor keeps failed jobs for a limited time, so the page may be gone.
                        ->helperText('De job monitor bewaart mislukte jobs een beperkte tijd; daarna werkt deze link niet meer.')
                        ->visible(fn (Notification $record): bool => $record->failedJobUrl() !== null)
                        ->columnSpanFull(),
                    KeyValueEntry::make('kenmerken')
                        ->label('Kenmerken')
                        ->keyLabel('Kenmerk')
                        ->valueLabel('Waarde')
                        ->state(fn (Notification $record): array => $record->notification['kenmerken'] ?? [])
                        ->placeholder('Geen kenmerken')
                        ->columnSpanFull(),
                ]),
            Section::make('Notificatie payload')
                ->schema([
                    TextEntry::make('notification')
                        ->label('JSON payload')
                        ->state(fn (Notification $record): string => (string) json_encode(
                            $record->notification,
                            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                        ))
                        ->fontFamily('mono')
                        ->columnSpanFull(),
                ]),
        ];
    }

    public static function type(Notification $record): string
    {
        return sprintf(
            '%s:%s',
            $record->notification['actie'] ?? '—',
            $record->notification['resource'] ?? '—'
        );
    }
}
