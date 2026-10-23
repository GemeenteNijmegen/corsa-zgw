<?php

use App\Enums\NotificationIgnoreReason;
use App\Enums\NotificationRoute;
use App\Enums\NotificationStatus;
use App\Filament\Resources\Batches\BatchResource;
use App\Filament\Resources\Notifications\NotificationResource;
use App\Filament\Resources\Notifications\Pages\ListNotifications;
use App\Models\Batch;
use App\Models\Notification;
use App\Models\User;
use Filament\Actions\Action;
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

test('it shows the zaak, the route, the type, the status and a link to the batch', function () {
    $direct = createDirectNotification(['zaak_identificatie' => 'ZAAK-0001', 'processed' => true, 'processed_at' => now()]);
    $batch = Batch::factory()->create(['zaak_identificatie' => 'ZAAK-0002']);
    $batched = createBatchNotification(['batch_id' => $batch->id, 'zaak_identificatie' => 'ZAAK-0002']);

    Livewire::test(ListNotifications::class)
        ->assertTableColumnStateSet('zaak_identificatie', 'ZAAK-0001', $direct)
        ->assertTableColumnStateSet('route', NotificationRoute::Direct, $direct)
        ->assertTableColumnStateSet('type', 'create:zaakinformatieobject', $direct)
        ->assertTableColumnStateSet('status', NotificationStatus::Processed, $direct)
        ->assertTableColumnStateSet('batch', null, $direct)
        ->assertTableColumnStateSet('route', NotificationRoute::Batch, $batched)
        ->assertTableColumnStateSet('status', NotificationStatus::Pending, $batched)
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

test('it can filter notifications on their status', function () {
    $processed = createDirectNotification(['processed' => true, 'processed_at' => now()]);
    $failed = Notification::factory()->direct()->failed()->create();
    $open = createDirectNotification();

    Livewire::test(ListNotifications::class)
        ->filterTable('status', NotificationStatus::Pending->value)
        ->assertCanSeeTableRecords([$open])
        ->assertCanNotSeeTableRecords([$processed, $failed])
        ->filterTable('status', NotificationStatus::Failed->value)
        ->assertCanSeeTableRecords([$failed])
        ->assertCanNotSeeTableRecords([$processed, $open])
        ->filterTable('status', NotificationStatus::Processed->value)
        ->assertCanSeeTableRecords([$processed])
        ->assertCanNotSeeTableRecords([$open, $failed]);
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
        ->assertMountedActionModalSee('2, 2026 11:00:00');
});

// ─── Read-only ───────────────────────────────────────────────────────────────

test('the notifications overview offers no actions that change anything', function () {
    $notification = createDirectNotification();

    $component = Livewire::test(ListNotifications::class);
    $table = $component->instance()->getTable();

    expect(array_keys(NotificationResource::getPages()))->toBe(['index'])
        ->and(array_keys($table->getFlatActions()))->toBe(['view_payload', 'failed_job'])
        ->and($table->getToolbarActions())->toBe([])
        ->and($table->getHeaderActions())->toBe([]);

    // The failed job action only links to the job monitor.
    $failed = Notification::factory()->direct()->failed()->create();
    Livewire::test(ListNotifications::class)
        ->assertActionHasUrl(TestAction::make('failed_job')->table($failed), $failed->failedJobUrl());

    $component
        ->assertActionDoesNotExist('create')
        ->assertActionDoesNotExist(TestAction::make('edit')->table($notification))
        ->assertActionDoesNotExist(TestAction::make('delete')->table($notification));
});

// ─── Route badge ─────────────────────────────────────────────────────────────

test('the route badge has the same colour in the table and in the view action', function () {
    $notification = createDirectNotification(['zaak_identificatie' => 'ZAAK-0001']);
    $directBadge = '/class="[^"]*fi-badge[^"]*fi-color-info[^"]*"[^>]*>\s*(?:<[^>]+>\s*)*Direct\b/s';

    $component = Livewire::test(ListNotifications::class);
    expect($component->html())->toMatch($directBadge);

    $component->mountAction(TestAction::make('view_payload')->table($notification));
    expect($component->getMountedActionModalHtml())->toMatch($directBadge);
});

// ─── Status and failed jobs ──────────────────────────────────────────────────

test('a notification that is not processed yet is shown as such and not as failed', function () {
    $open = createDirectNotification();

    Livewire::test(ListNotifications::class)
        ->assertTableColumnStateSet('status', NotificationStatus::Pending, $open)
        ->assertSee('Nog niet verwerkt')
        ->assertActionHidden(TestAction::make('failed_job')->table($open));

    expect(NotificationStatus::Pending->getColor())->toBe('gray')
        ->and(NotificationStatus::Pending->getIcon())->toBe('heroicon-o-clock')
        ->and(NotificationStatus::Failed->getColor())->toBe('danger');
});

test('a failed notification links to its failed job in a new tab and shows the failure', function () {
    $failed = Notification::factory()->direct()->failed()->create(['failure' => 'RequestException: HTTP 503']);

    Livewire::test(ListNotifications::class)
        ->assertTableColumnStateSet('status', NotificationStatus::Failed, $failed)
        ->assertSee('Mislukt')
        ->assertSee('RequestException: HTTP 503')
        ->assertActionVisible(TestAction::make('failed_job')->table($failed))
        ->assertActionHasUrl(TestAction::make('failed_job')->table($failed), $failed->failedJobUrl())
        ->assertActionShouldOpenUrlInNewTab(TestAction::make('failed_job')->table($failed));
});

test('a failed notification without a job id shows the failure but no link', function () {
    $failed = Notification::factory()->direct()->failed()->create(['failed_job_id' => null]);

    Livewire::test(ListNotifications::class)
        ->assertTableColumnStateSet('status', NotificationStatus::Failed, $failed)
        ->assertActionHidden(TestAction::make('failed_job')->table($failed))
        ->mountAction(TestAction::make('view_payload')->table($failed))
        ->assertMountedActionModalSee('ConnectionException: Connection timed out')
        ->assertMountedActionModalDontSee('Openen in de job monitor');
});

test('the view action of a failed notification shows the failure and links to the failed job', function () {
    $failed = Notification::factory()->direct()->failed()->create();

    Livewire::test(ListNotifications::class)
        ->mountAction(TestAction::make('view_payload')->table($failed))
        ->assertMountedActionModalSee('Mislukt')
        ->assertMountedActionModalSee('ConnectionException: Connection timed out')
        ->assertMountedActionModalSee('Openen in de job monitor')
        ->assertMountedActionModalSeeHtml('href="'.e($failed->failedJobUrl()).'"')
        ->assertMountedActionModalSeeHtml('target="_blank"');
});

// ─── Layout ──────────────────────────────────────────────────────────────────

test('the record actions are icon buttons with a tooltip, so they stay in view next to the columns', function () {
    $failed = Notification::factory()->direct()->failed()->create();

    Livewire::test(ListNotifications::class)
        ->assertActionExists(
            TestAction::make('view_payload')->table($failed),
            fn (Action $action): bool => $action->isIconButton() && $action->getTooltip() === 'Bekijk payload',
        )
        ->assertActionExists(
            TestAction::make('failed_job')->table($failed),
            fn (Action $action): bool => $action->isIconButton() && $action->getTooltip() === 'Mislukte job',
        )
        ->assertSeeHtml('aria-label="Bekijk payload"')
        ->assertSeeHtml('aria-label="Mislukte job"');
});

test('the overview hides the processing time by default and can show it again', function () {
    $processed = createDirectNotification(['processed' => true, 'processed_at' => now()]);

    $component = Livewire::test(ListNotifications::class);

    foreach (['created_at', 'zaak_identificatie', 'route', 'type', 'status', 'batch'] as $column) {
        $component->assertCanRenderTableColumn($column);
    }

    $processedAt = $component->instance()->getTable()->getColumn('processed_at');
    expect($processedAt->isToggleable())->toBeTrue()
        ->and($processedAt->isToggledHiddenByDefault())->toBeTrue();

    $component->assertDontSeeHtml('.column.processed_at"')
        ->toggleAllTableColumns()
        ->assertCanRenderTableColumn('processed_at')
        ->assertTableColumnStateSet('processed_at', $processed->processed_at, $processed);
});
