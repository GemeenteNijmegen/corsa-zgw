<?php

use App\Enums\NotificationIgnoreReason;
use App\Filament\Resources\Batches\Pages\ListBatches;
use App\Filament\Resources\Batches\Pages\ViewBatch;
use App\Filament\Resources\Batches\RelationManagers\NotificationsRelationManager;
use App\Filament\Resources\Catalogi\Pages\ListCatalogi;
use App\Filament\Resources\IgnoredNotifications\Pages\ListIgnoredNotifications;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\RelationManagers\TokensRelationManager;
use App\Filament\Resources\ZaaktypeMappings\Pages\ListZaaktypeMappings;
use App\Models\Batch;
use App\Models\Catalogus;
use App\Models\Notification;
use App\Models\User;
use App\Models\ZaaktypeMapping;
use Filament\Actions\Testing\TestAction;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

// Every timestamp in these tests is stored as 10:00 UTC on 2 January (winter time),
// which is 11:00 in Amsterdam.
const STORED_UTC = '2026-01-02 10:00:00';
const PAYLOAD_AANMAAKDATUM = '2026-01-02T10:00:00Z';

beforeEach(function () {
    $this->actingAs(User::factory()->create(['email_verified_at' => null]));
});

/**
 * @param  array<string, mixed>  $attributes
 */
function notificationAtStoredTime(array $attributes = []): Notification
{
    return Notification::factory()->create([
        'zaak_identificatie' => 'ZAAK-0001',
        'notification' => [
            'actie' => 'create',
            'kanaal' => 'zaken',
            'resource' => 'zaak',
            'hoofdObject' => 'https://openzaak.example.com/zaken/api/v1/zaken/0b6a8f4e-0000-4000-8000-000000000001',
            'resourceUrl' => 'https://openzaak.example.com/zaken/api/v1/zaken/0b6a8f4e-0000-4000-8000-000000000001',
            'aanmaakdatum' => PAYLOAD_AANMAAKDATUM,
            'kenmerken' => [],
        ],
        'created_at' => STORED_UTC,
        ...$attributes,
    ]);
}

test('the panel shows times in Amsterdam while the application keeps storing them in UTC', function () {
    $this->travelTo(Carbon::parse(STORED_UTC, 'UTC'));

    Notification::factory()->ignored(NotificationIgnoreReason::UnsupportedChannel)->create();

    expect(FilamentTimezone::get())->toBe('Europe/Amsterdam')
        ->and(config('app.timezone'))->toBe('UTC')
        ->and((string) DB::table('notifications')->value('ignored_at'))->toBe(STORED_UTC);
});

test('the ignored notifications overview and its view action show Amsterdam time', function () {
    $notification = notificationAtStoredTime([
        'ignored_at' => STORED_UTC,
        'ignored_reason' => NotificationIgnoreReason::UnknownZaaktype,
        // Received earlier than ignored, so the received time is shown as well.
        'created_at' => '2026-01-02 09:00:00',
    ]);

    Livewire::test(ListIgnoredNotifications::class)
        ->assertSee('2026 11:00:00')
        ->assertDontSee('2026 10:00:00')
        ->mountAction(TestAction::make('view_payload')->table($notification))
        ->assertMountedActionModalSee('2026 11:00:00')
        ->assertMountedActionModalSee('2026 10:00:00')
        ->assertMountedActionModalDontSee('2026 09:00:00');
});

test('the panel follows daylight saving time in Amsterdam', function () {
    notificationAtStoredTime([
        'ignored_at' => '2026-07-02 10:00:00',
        'ignored_reason' => NotificationIgnoreReason::UnknownZaaktype,
    ]);

    Livewire::test(ListIgnoredNotifications::class)
        ->assertSee('2026 12:00:00')
        ->assertDontSee('2026 10:00:00');
});

test('the batches overview shows Amsterdam time', function () {
    Batch::factory()->create([
        'created_at' => STORED_UTC,
        'locked_at' => STORED_UTC,
        'processed_at' => STORED_UTC,
    ]);

    Livewire::test(ListBatches::class)
        ->assertSee('2026 11:00:00')
        ->assertDontSee('2026 10:00:00');
});

test('the batch page shows Amsterdam time', function () {
    $batch = Batch::factory()->create([
        'zaak_identificatie' => 'ZAAK-0001',
        'created_at' => STORED_UTC,
        'locked_at' => STORED_UTC,
        'processed_at' => STORED_UTC,
    ]);
    notificationAtStoredTime(['batch_id' => $batch->id, 'processed' => true, 'processed_at' => STORED_UTC]);

    Livewire::test(ViewBatch::class, ['record' => $batch->getRouteKey()])
        ->assertSee('2026 11:00:00')
        ->assertDontSee('10:00:00');
});

test('the notifications of a batch show Amsterdam time in the panel format, also for the aanmaakdatum from the payload', function () {
    $batch = Batch::factory()->create(['zaak_identificatie' => 'ZAAK-0001']);
    $notification = notificationAtStoredTime(['batch_id' => $batch->id, 'processed' => true, 'processed_at' => STORED_UTC]);

    Livewire::test(NotificationsRelationManager::class, [
        'ownerRecord' => $batch,
        'pageClass' => ViewBatch::class,
    ])
        ->assertTableColumnFormattedStateSet('aanmaakdatum', 'jan. 2, 2026 11:00:00', $notification)
        ->assertTableColumnFormattedStateSet('processed_at', 'jan. 2, 2026 11:00:00', $notification)
        ->assertDontSee('02-01-2026')
        ->assertDontSee('10:00:00');
});

test('the users overview and the tokens of a user show Amsterdam time', function () {
    $user = User::factory()->create([
        'email_verified_at' => STORED_UTC,
        'created_at' => STORED_UTC,
        'updated_at' => STORED_UTC,
    ]);
    $user->createToken('example-token')->accessToken->forceFill([
        'created_at' => STORED_UTC,
        'updated_at' => STORED_UTC,
    ])->save();

    Livewire::test(ListUsers::class)
        ->toggleAllTableColumns()
        ->assertSee('2026 11:00:00')
        ->assertDontSee('2026 10:00:00');

    Livewire::test(TokensRelationManager::class, [
        'ownerRecord' => $user,
        'pageClass' => EditUser::class,
    ])
        ->toggleAllTableColumns()
        ->assertSee('2026 11:00:00')
        ->assertDontSee('2026 10:00:00');
});

test('the catalogi and zaaktype mappings overviews show Amsterdam time', function () {
    Catalogus::factory()->create(['last_synced_at' => STORED_UTC]);
    ZaaktypeMapping::factory()->create(['synced_at' => STORED_UTC]);

    Livewire::test(ListCatalogi::class)
        ->assertSee('2026 11:00:00')
        ->assertDontSee('2026 10:00:00');

    Livewire::test(ListZaaktypeMappings::class)
        ->toggleAllTableColumns()
        ->assertSee('2026 11:00:00')
        ->assertDontSee('2026 10:00:00');
});
