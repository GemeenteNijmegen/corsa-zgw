<?php

use App\Enums\NotificationIgnoreReason;
use App\Filament\Resources\Batches\Pages\ViewBatch;
use App\Filament\Resources\IgnoredNotifications\IgnoredNotificationResource;
use App\Filament\Resources\IgnoredNotifications\Pages\ListIgnoredNotifications;
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
 * @param  array<string, mixed>  $payload
 */
function createIgnoredNotification(
    NotificationIgnoreReason $reason,
    array $attributes = [],
    array $payload = [],
): Notification {
    return Notification::factory()->ignored($reason)->create([
        ...$attributes,
        'notification' => [
            'actie' => 'create',
            'kanaal' => 'zaken',
            'resource' => 'zaak',
            'hoofdObject' => 'https://openzaak.example.com/zaken/api/v1/zaken/0b6a8f4e-0000-4000-8000-000000000001',
            'resourceUrl' => 'https://openzaak.example.com/zaken/api/v1/zaken/0b6a8f4e-0000-4000-8000-000000000001',
            'aanmaakdatum' => '2026-01-02T10:00:00+00:00',
            'kenmerken' => [],
            ...$payload,
        ],
    ]);
}

// ─── List page ───────────────────────────────────────────────────────────────

/**
 * The panel navigation links to the debug dashboard, whose routes are not registered
 * while it is disabled in the test environment. Register a stand-in route with the
 * same name so the full panel page can be rendered.
 */
function registerDebugDashboardRouteStandIn(): void
{
    Route::get('/telescope-stand-in', fn () => '')->name('telescope');
    Route::getRoutes()->refreshNameLookups();
}

test('it renders the ignored notifications page in the panel', function () {
    registerDebugDashboardRouteStandIn();
    createIgnoredNotification(NotificationIgnoreReason::UnknownZaaktype, ['zaak_identificatie' => 'ZAAK-0001']);

    $this->withoutVite()
        ->get(IgnoredNotificationResource::getUrl('index'))
        ->assertSuccessful()
        ->assertSeeText('Genegeerde notificaties')
        ->assertDontSeeText('Genegeerde Notificaties')
        ->assertSeeText('ZAAK-0001')
        ->assertSeeText('Zaaktype zonder mapping');
});

test('it lists the ignored notifications page in the monitoring group of the navigation', function () {
    registerDebugDashboardRouteStandIn();
    Livewire::test(ListIgnoredNotifications::class);

    $monitoring = collect(Filament::getPanel('admin')->getNavigation())
        ->first(fn (NavigationGroup $group): bool => $group->getLabel() === 'Monitoring');

    $items = collect($monitoring->getItems())
        ->mapWithKeys(fn (NavigationItem $item): array => [$item->getLabel() => $item->getUrl()]);

    expect($items->get('Genegeerde notificaties'))->toBe(IgnoredNotificationResource::getUrl('index'));
});

test('it shows only ignored notifications and not processed or pending ones', function () {
    $ignored = createIgnoredNotification(NotificationIgnoreReason::UnknownZaaktype, ['zaak_identificatie' => 'ZAAK-0001']);
    $processed = Notification::factory()->processed()->create(['zaak_identificatie' => 'ZAAK-0001']);
    $pending = Notification::factory()->create(['zaak_identificatie' => 'ZAAK-0001']);

    Livewire::test(ListIgnoredNotifications::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$ignored])
        ->assertCanNotSeeTableRecords([$processed, $pending])
        ->assertCountTableRecords(1);
});

test('it shows the newest ignored notification first', function () {
    $older = createIgnoredNotification(NotificationIgnoreReason::UnsupportedAction, ['ignored_at' => now()->subDays(2)]);
    $newest = createIgnoredNotification(NotificationIgnoreReason::UnsupportedAction, ['ignored_at' => now()->subHour()]);
    $middle = createIgnoredNotification(NotificationIgnoreReason::UnsupportedAction, ['ignored_at' => now()->subDay()]);

    Livewire::test(ListIgnoredNotifications::class)
        ->assertCanSeeTableRecords([$newest, $middle, $older], inOrder: true);
});

test('it shows one date column, so the view action stays in view', function () {
    $component = Livewire::test(ListIgnoredNotifications::class);

    expect(array_keys($component->instance()->getTable()->getColumns()))
        ->toBe(['ignored_at', 'zaak_identificatie', 'ignored_reason', 'kanaal', 'actie', 'resource']);
});

test('it shows the zaak, a readable reason, the kanaal, the actie and the resource', function () {
    $withZaak = createIgnoredNotification(
        NotificationIgnoreReason::InactiveZaaktype,
        ['zaak_identificatie' => 'ZAAK-0001'],
        ['actie' => 'create', 'resource' => 'status'],
    );
    $withoutZaak = createIgnoredNotification(
        NotificationIgnoreReason::UnsupportedChannel,
        ['zaak_identificatie' => null],
        ['kanaal' => 'besluiten', 'actie' => 'destroy', 'resource' => 'besluit'],
    );

    Livewire::test(ListIgnoredNotifications::class)
        ->assertTableColumnStateSet('zaak_identificatie', 'ZAAK-0001', $withZaak)
        ->assertTableColumnStateSet('ignored_reason', NotificationIgnoreReason::InactiveZaaktype, $withZaak)
        ->assertTableColumnStateSet('kanaal', 'zaken', $withZaak)
        ->assertTableColumnStateSet('actie', 'create', $withZaak)
        ->assertTableColumnStateSet('resource', 'status', $withZaak)
        ->assertTableColumnStateSet('zaak_identificatie', null, $withoutZaak)
        ->assertTableColumnStateSet('kanaal', 'besluiten', $withoutZaak)
        ->assertTableColumnStateSet('actie', 'destroy', $withoutZaak)
        ->assertTableColumnStateSet('resource', 'besluit', $withoutZaak)
        ->assertSee('Mapping zaaktype niet actief')
        ->assertSee('Kanaal niet verwerkt');
});

test('it can search ignored notifications by zaak', function () {
    $matching = createIgnoredNotification(NotificationIgnoreReason::UnknownZaaktype, ['zaak_identificatie' => 'ZAAK-0001']);
    $other = createIgnoredNotification(NotificationIgnoreReason::UnknownZaaktype, ['zaak_identificatie' => 'ZAAK-0002']);

    Livewire::test(ListIgnoredNotifications::class)
        ->searchTable('ZAAK-0001')
        ->assertCanSeeTableRecords([$matching])
        ->assertCanNotSeeTableRecords([$other]);
});

// ─── Filters ─────────────────────────────────────────────────────────────────

test('it can filter ignored notifications by reason', function () {
    $unknown = createIgnoredNotification(NotificationIgnoreReason::UnknownZaaktype, ['zaak_identificatie' => 'ZAAK-0001']);
    $inactive = createIgnoredNotification(NotificationIgnoreReason::InactiveZaaktype, ['zaak_identificatie' => 'ZAAK-0002']);
    $channel = createIgnoredNotification(NotificationIgnoreReason::UnsupportedChannel, ['zaak_identificatie' => null]);

    Livewire::test(ListIgnoredNotifications::class)
        ->filterTable('ignored_reason', NotificationIgnoreReason::InactiveZaaktype->value)
        ->assertCanSeeTableRecords([$inactive])
        ->assertCanNotSeeTableRecords([$unknown, $channel]);
});

test('it can filter ignored notifications by zaak', function () {
    $first = createIgnoredNotification(NotificationIgnoreReason::UnknownZaaktype, ['zaak_identificatie' => 'ZAAK-0001']);
    $second = createIgnoredNotification(NotificationIgnoreReason::InactiveZaaktype, ['zaak_identificatie' => 'ZAAK-0001']);
    $otherZaak = createIgnoredNotification(NotificationIgnoreReason::UnknownZaaktype, ['zaak_identificatie' => 'ZAAK-0002']);
    $noZaak = createIgnoredNotification(NotificationIgnoreReason::UnsupportedAction, ['zaak_identificatie' => null]);

    Livewire::test(ListIgnoredNotifications::class)
        ->filterTable('zaak_identificatie', 'ZAAK-0001')
        ->assertCanSeeTableRecords([$first, $second])
        ->assertCanNotSeeTableRecords([$otherZaak, $noZaak]);
});

test('the zaak filter searches the zaken of ignored notifications only, case-insensitively', function () {
    createIgnoredNotification(NotificationIgnoreReason::UnknownZaaktype, ['zaak_identificatie' => 'ZAAK-0001']);
    createIgnoredNotification(NotificationIgnoreReason::UnknownZaaktype, ['zaak_identificatie' => 'ZAAK-0001']);
    createIgnoredNotification(NotificationIgnoreReason::UnknownZaaktype, ['zaak_identificatie' => 'ZAAK-0002']);
    createIgnoredNotification(NotificationIgnoreReason::UnsupportedAction, ['zaak_identificatie' => null]);
    Notification::factory()->processed()->create(['zaak_identificatie' => 'ZAAK-0003']);

    $component = Livewire::test(ListIgnoredNotifications::class);
    $filter = $component->instance()->getTable()->getFilter('zaak_identificatie');
    $search = $filter->getSearchResultsUsingCallback();

    expect($search('zaak-000'))->toBe(['ZAAK-0001' => 'ZAAK-0001', 'ZAAK-0002' => 'ZAAK-0002'])
        ->and($search('0002'))->toBe(['ZAAK-0002' => 'ZAAK-0002'])
        ->and($search('0003'))->toBe([]);
});

test('the zaak filter can be preset through the url', function () {
    $matching = createIgnoredNotification(NotificationIgnoreReason::UnknownZaaktype, ['zaak_identificatie' => 'ZAAK-0001']);
    $other = createIgnoredNotification(NotificationIgnoreReason::UnknownZaaktype, ['zaak_identificatie' => 'ZAAK-0002']);

    Livewire::withQueryParams(['filters' => ['zaak_identificatie' => ['value' => 'ZAAK-0001']]])
        ->test(ListIgnoredNotifications::class)
        ->assertCanSeeTableRecords([$matching])
        ->assertCanNotSeeTableRecords([$other]);
});

// ─── Grouping per zaak ───────────────────────────────────────────────────────

test('it groups ignored notifications per zaak with a count per group', function () {
    $zaakOneFirst = createIgnoredNotification(NotificationIgnoreReason::UnknownZaaktype, ['zaak_identificatie' => 'ZAAK-0001', 'ignored_at' => now()->subHours(3)]);
    $zaakTwo = createIgnoredNotification(NotificationIgnoreReason::InactiveZaaktype, ['zaak_identificatie' => 'ZAAK-0002', 'ignored_at' => now()->subHours(2)]);
    $zaakOneSecond = createIgnoredNotification(NotificationIgnoreReason::UnknownZaaktype, ['zaak_identificatie' => 'ZAAK-0001', 'ignored_at' => now()->subHour()]);
    $noZaak = createIgnoredNotification(NotificationIgnoreReason::UnsupportedChannel, ['zaak_identificatie' => null, 'ignored_at' => now()]);

    $component = Livewire::test(ListIgnoredNotifications::class)
        ->set('tableGrouping', 'zaak_identificatie:desc')
        ->assertCanSeeTableRecords([$zaakTwo, $zaakOneSecond, $zaakOneFirst], inOrder: true)
        ->assertCanSeeTableRecords([$noZaak])
        ->assertSee('ZAAK-0001')
        ->assertSee('ZAAK-0002')
        ->assertSee('Geen zaak');

    // Each group ends with a summary row that counts its notifications, and the table
    // ends with one for all of them.
    preg_match_all(
        '/<th[^>]*fi-ta-summary-row-heading-cell[^>]*>(.*?)<\/th>.*?fi-ta-text-summary-label">\s*Aantal\s*<\/span>.*?<span>\s*(\d+)\s*<\/span>/s',
        $component->html(),
        $matches,
        PREG_SET_ORDER,
    );

    $counts = collect($matches)->mapWithKeys(fn (array $match): array => [
        trim(strip_tags($match[1])) => (int) $match[2],
    ])->all();

    expect($counts)->toMatchArray([
        'ZAAK-0002 samenvatting' => 1,
        'ZAAK-0001 samenvatting' => 2,
        'Geen zaak samenvatting' => 1,
    ])->and(array_sum($counts))->toBe(8);
});

// ─── View action ─────────────────────────────────────────────────────────────

test('the view action shows the stored payload including the kenmerken', function () {
    $notification = createIgnoredNotification(
        NotificationIgnoreReason::UnknownZaaktype,
        ['zaak_identificatie' => 'ZAAK-0001'],
        ['kenmerken' => [
            'bronorganisatie' => '000000000',
            'zaaktype' => 'https://openzaak.example.com/catalogi/api/v1/zaaktypen/0b6a8f4e-0000-4000-8000-000000000099',
        ]],
    );

    Livewire::test(ListIgnoredNotifications::class)
        ->mountAction(TestAction::make('view_payload')->table($notification))
        ->assertMountedActionModalSee('bronorganisatie')
        ->assertMountedActionModalSee('https://openzaak.example.com/catalogi/api/v1/zaaktypen/0b6a8f4e-0000-4000-8000-000000000099')
        ->assertMountedActionModalSee('https://openzaak.example.com/zaken/api/v1/zaken/0b6a8f4e-0000-4000-8000-000000000001')
        ->assertMountedActionModalSee('Zaaktype zonder mapping');
});

test('the view action shows the json payload without escaped slashes', function () {
    $notification = createIgnoredNotification(NotificationIgnoreReason::UnknownZaaktype, ['zaak_identificatie' => 'ZAAK-0001']);

    Livewire::test(ListIgnoredNotifications::class)
        ->mountAction(TestAction::make('view_payload')->table($notification))
        ->assertMountedActionModalSee('"hoofdObject": "https://openzaak.example.com/zaken/api/v1/zaken/0b6a8f4e-0000-4000-8000-000000000001"')
        ->assertMountedActionModalDontSee('https:\\/\\/', false);
});

test('the view action shows the aanmaakdatum in the same format as the other dates', function () {
    $notification = createIgnoredNotification(
        NotificationIgnoreReason::UnknownZaaktype,
        ['zaak_identificatie' => 'ZAAK-0001', 'ignored_at' => '2026-01-03 09:30:00'],
    );

    Livewire::test(ListIgnoredNotifications::class)
        ->mountAction(TestAction::make('view_payload')->table($notification))
        ->assertMountedActionModalSee('2, 2026 11:00:00')
        ->assertMountedActionModalSee('3, 2026 10:30:00')
        ->assertMountedActionModalDontSee('02-01-2026');
});

test('the view action shows when a notification was received only when that differs from when it was ignored', function () {
    $ignoredOnArrival = createIgnoredNotification(
        NotificationIgnoreReason::UnknownZaaktype,
        ['created_at' => '2026-01-03 09:30:00', 'ignored_at' => '2026-01-03 09:30:01'],
    );
    $ignoredLater = createIgnoredNotification(
        NotificationIgnoreReason::UnsupportedAction,
        ['created_at' => '2026-01-03 08:00:00', 'ignored_at' => '2026-01-03 09:30:00'],
    );

    Livewire::test(ListIgnoredNotifications::class)
        ->mountAction(TestAction::make('view_payload')->table($ignoredOnArrival))
        ->assertMountedActionModalDontSee('Ontvangen');

    Livewire::test(ListIgnoredNotifications::class)
        ->mountAction(TestAction::make('view_payload')->table($ignoredLater))
        ->assertMountedActionModalSee('Ontvangen')
        ->assertMountedActionModalSee('3, 2026 09:00:00');
});

test('the view action handles a notification stored without kenmerken', function () {
    $notification = Notification::factory()->ignored(NotificationIgnoreReason::UnsupportedAction)->create([
        'zaak_identificatie' => null,
    ]);

    Livewire::test(ListIgnoredNotifications::class)
        ->mountAction(TestAction::make('view_payload')->table($notification))
        ->assertHasNoActionErrors()
        ->assertMountedActionModalSee('Geen kenmerken');
});

// ─── Read-only ───────────────────────────────────────────────────────────────

test('the overview offers no actions that change anything', function () {
    $notification = createIgnoredNotification(NotificationIgnoreReason::UnknownZaaktype, ['zaak_identificatie' => 'ZAAK-0001']);

    $component = Livewire::test(ListIgnoredNotifications::class);
    $table = $component->instance()->getTable();

    expect(array_keys(IgnoredNotificationResource::getPages()))->toBe(['index'])
        ->and(array_keys($table->getFlatActions()))->toBe(['view_payload'])
        ->and($table->getToolbarActions())->toBe([])
        ->and($table->getEmptyStateActions())->toBe([])
        ->and($table->getHeaderActions())->toBe([]);

    $component
        ->assertActionDoesNotExist('create')
        ->assertActionDoesNotExist(TestAction::make('edit')->table($notification))
        ->assertActionDoesNotExist(TestAction::make('delete')->table($notification))
        ->assertActionDoesNotExist(TestAction::make('delete')->table()->bulk());
});

// ─── From a batch ────────────────────────────────────────────────────────────

test('the batch page links to the ignored notifications of its zaak when there are any', function () {
    $batch = Batch::factory()->create(['zaak_identificatie' => 'ZAAK-0001']);
    createIgnoredNotification(NotificationIgnoreReason::InactiveZaaktype, ['zaak_identificatie' => 'ZAAK-0001']);

    Livewire::test(ViewBatch::class, ['record' => $batch->getRouteKey()])
        ->assertActionVisible('ignored_notifications')
        ->assertActionHasUrl('ignored_notifications', IgnoredNotificationResource::getUrl('index', [
            'filters' => ['zaak_identificatie' => ['value' => 'ZAAK-0001']],
        ]));
});

test('the batch page shows no link when its zaak has no ignored notifications', function () {
    $batch = Batch::factory()->create(['zaak_identificatie' => 'ZAAK-0001']);
    createIgnoredNotification(NotificationIgnoreReason::InactiveZaaktype, ['zaak_identificatie' => 'ZAAK-0002']);
    Notification::factory()->create(['batch_id' => $batch->id, 'zaak_identificatie' => 'ZAAK-0001']);

    Livewire::test(ViewBatch::class, ['record' => $batch->getRouteKey()])
        ->assertActionHidden('ignored_notifications');
});
