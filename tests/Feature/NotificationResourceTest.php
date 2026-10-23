<?php

use App\Enums\NotificationIgnoreReason;
use App\Enums\NotificationRoute;
use App\Filament\Resources\Batches\BatchResource;
use App\Filament\Resources\Notifications\NotificationResource;
use App\Filament\Resources\Notifications\Pages\ListNotifications;
use App\Models\Batch;
use App\Models\Notification;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

/**
 * @param  array<string, mixed>  $attributes
 */
function createDirectNotification(array $attributes = []): Notification
{
    return Notification::factory()->direct()->create($attributes);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function createBatchNotification(array $attributes = []): Notification
{
    return Notification::factory()->create($attributes);
}

/**
 * The panel navigation links to the debug dashboard, whose routes are not registered while it
 * is disabled in the test environment. Register a stand-in route with the same name so the
 * full panel page can be rendered.
 */
function registerTelescopeRouteStandIn(): void
{
    Route::get('/telescope-stand-in', fn () => '')->name('telescope');
    Route::getRoutes()->refreshNameLookups();
}

// ─── List page ───────────────────────────────────────────────────────────────

test('it renders the notifications page in the panel', function () {
    registerTelescopeRouteStandIn();
    createDirectNotification(['zaak_identificatie' => 'ZAAK-0001']);

    $this->withoutVite()
        ->get(NotificationResource::getUrl('index'))
        ->assertSuccessful()
        ->assertSeeText('Notificaties')
        ->assertSeeText('ZAAK-0001')
        ->assertSeeText('Direct')
        ->assertSeeText('create:zaakinformatieobject');
});

test('it lists the notifications page in the monitoring group of the navigation', function () {
    registerTelescopeRouteStandIn();
    Livewire::test(ListNotifications::class);

    $monitoring = collect(Filament::getPanel('admin')->getNavigation())
        ->first(fn (NavigationGroup $group): bool => $group->getLabel() === 'Monitoring');

    $items = collect($monitoring->getItems())
        ->mapWithKeys(fn (NavigationItem $item): array => [$item->getLabel() => $item->getUrl()]);

    expect($items->get('Notificaties'))->toBe(NotificationResource::getUrl('index'))
        ->and($items->keys()->take(3)->all())->toBe(['Notificatie-batches', 'Notificaties', 'Genegeerde notificaties']);
});

test('it shows notifications on both routes and not the ignored ones', function () {
    $direct = createDirectNotification();
    $batched = createBatchNotification();
    $processed = createBatchNotification()->fill(['processed' => true, 'processed_at' => now()]);
    $processed->save();
    $ignored = Notification::factory()->ignored(NotificationIgnoreReason::UnknownZaaktype)->create();

    Livewire::test(ListNotifications::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$direct, $batched, $processed])
        ->assertCanNotSeeTableRecords([$ignored])
        ->assertCountTableRecords(3);
});

test('it shows the newest notification first', function () {
    $older = createDirectNotification(['created_at' => now()->subDays(2)]);
    $newest = createBatchNotification(['created_at' => now()->subHour()]);
    $middle = createDirectNotification(['created_at' => now()->subDay()]);

    Livewire::test(ListNotifications::class)
        ->assertCanSeeTableRecords([$newest, $middle, $older], inOrder: true);
});

test('it shows the zaak, the route, the type, whether it is processed and a link to the batch', function () {
    $direct = createDirectNotification(['zaak_identificatie' => 'ZAAK-0001', 'processed' => true, 'processed_at' => now()]);
    $batch = Batch::factory()->create(['zaak_identificatie' => 'ZAAK-0002']);
    $batched = createBatchNotification(['batch_id' => $batch->id, 'zaak_identificatie' => 'ZAAK-0002']);

    Livewire::test(ListNotifications::class)
        ->assertTableColumnStateSet('zaak_identificatie', 'ZAAK-0001', $direct)
        ->assertTableColumnStateSet('route', NotificationRoute::Direct, $direct)
        ->assertTableColumnStateSet('type', 'create:zaakinformatieobject', $direct)
        ->assertTableColumnStateSet('processed', true, $direct)
        ->assertTableColumnStateSet('batch', null, $direct)
        ->assertTableColumnStateSet('route', NotificationRoute::Batch, $batched)
        ->assertTableColumnStateSet('processed', false, $batched)
        ->assertTableColumnStateSet('batch', 'Bekijk batch', $batched)
        ->assertSee(BatchResource::getUrl('view', ['record' => $batch->id]));
});

test('it can search notifications by zaak', function () {
    $matching = createDirectNotification(['zaak_identificatie' => 'ZAAK-0001']);
    $other = createDirectNotification(['zaak_identificatie' => 'ZAAK-0002']);

    Livewire::test(ListNotifications::class)
        ->searchTable('ZAAK-0001')
        ->assertCanSeeTableRecords([$matching])
        ->assertCanNotSeeTableRecords([$other]);
});

// ─── Filters ─────────────────────────────────────────────────────────────────

test('it can filter notifications by route', function (NotificationRoute $route) {
    $direct = createDirectNotification();
    $batched = createBatchNotification();

    [$shown, $hidden] = $route === NotificationRoute::Direct ? [$direct, $batched] : [$batched, $direct];

    Livewire::test(ListNotifications::class)
        ->filterTable('route', $route->value)
        ->assertCanSeeTableRecords([$shown])
        ->assertCanNotSeeTableRecords([$hidden]);
})->with([NotificationRoute::Direct, NotificationRoute::Batch]);

test('it can filter notifications on whether they are processed', function () {
    $processed = createDirectNotification(['processed' => true, 'processed_at' => now()]);
    $open = createDirectNotification();

    Livewire::test(ListNotifications::class)
        ->filterTable('processed', false)
        ->assertCanSeeTableRecords([$open])
        ->assertCanNotSeeTableRecords([$processed])
        ->filterTable('processed', true)
        ->assertCanSeeTableRecords([$processed])
        ->assertCanNotSeeTableRecords([$open]);
});

test('it can filter notifications by zaak', function () {
    $matching = createDirectNotification(['zaak_identificatie' => 'ZAAK-0001']);
    $other = createBatchNotification(['zaak_identificatie' => 'ZAAK-0002']);

    Livewire::test(ListNotifications::class)
        ->filterTable('zaak_identificatie', 'ZAAK-0001')
        ->assertCanSeeTableRecords([$matching])
        ->assertCanNotSeeTableRecords([$other]);
});

test('the zaak filter searches the zaken of notifications that are not ignored', function () {
    createDirectNotification(['zaak_identificatie' => 'ZAAK-0001']);
    createBatchNotification(['zaak_identificatie' => 'ZAAK-0002']);
    Notification::factory()->ignored()->create(['zaak_identificatie' => 'ZAAK-0003']);

    $component = Livewire::test(ListNotifications::class);
    $search = $component->instance()->getTable()->getFilter('zaak_identificatie')->getSearchResultsUsingCallback();

    expect($search('zaak-000'))->toBe(['ZAAK-0001' => 'ZAAK-0001', 'ZAAK-0002' => 'ZAAK-0002'])
        ->and($search('0003'))->toBe([]);
});

// ─── View action ─────────────────────────────────────────────────────────────

test('the view action shows the route and the stored payload including the kenmerken', function () {
    $notification = createDirectNotification(['zaak_identificatie' => 'ZAAK-0001']);
    $notification->update(['notification' => [
        ...$notification->notification,
        'aanmaakdatum' => '2026-01-02T10:00:00+00:00',
        'kenmerken' => ['bronorganisatie' => '000000000'],
    ]]);

    Livewire::test(ListNotifications::class)
        ->mountAction(TestAction::make('view_payload')->table($notification))
        ->assertHasNoActionErrors()
        ->assertMountedActionModalSee('Direct')
        ->assertMountedActionModalSee('bronorganisatie')
        ->assertMountedActionModalSee($notification->notification['resourceUrl'])
        ->assertMountedActionModalSee('02-01-2026 10:00:00');
});

// ─── Read-only ───────────────────────────────────────────────────────────────

test('the notifications overview offers no actions that change anything', function () {
    $notification = createDirectNotification();

    $component = Livewire::test(ListNotifications::class);
    $table = $component->instance()->getTable();

    expect(array_keys(NotificationResource::getPages()))->toBe(['index'])
        ->and(array_keys($table->getFlatActions()))->toBe(['view_payload'])
        ->and($table->getToolbarActions())->toBe([])
        ->and($table->getHeaderActions())->toBe([]);

    $component
        ->assertActionDoesNotExist('create')
        ->assertActionDoesNotExist(TestAction::make('edit')->table($notification))
        ->assertActionDoesNotExist(TestAction::make('delete')->table($notification));
});
