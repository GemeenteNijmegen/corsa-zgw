<?php

use App\Enums\NotificationStatus;
use App\Filament\Resources\Batches\Pages\ListBatches;
use App\Filament\Resources\Batches\Pages\ViewBatch;
use App\Filament\Resources\Batches\RelationManagers\NotificationsRelationManager;
use App\Filament\Resources\Batches\Schemas\BatchInfolist;
use App\Models\Batch;
use App\Models\Notification;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Livewire\Livewire;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

// ─── List page ───────────────────────────────────────────────────────────────

test('it can render the batches list page', function () {
    Livewire::test(ListBatches::class)
        ->assertSuccessful();
});

test('it shows batches in the table', function () {
    $batches = Batch::factory()->count(3)->create();

    Livewire::test(ListBatches::class)
        ->assertCanSeeTableRecords($batches);
});

test('it can search batches by zaak_identificatie', function () {
    $matching = Batch::factory()->create(['zaak_identificatie' => 'ZAAK-2026-1111']);
    $other = Batch::factory()->create(['zaak_identificatie' => 'ZAAK-2026-9999']);

    Livewire::test(ListBatches::class)
        ->searchTable('ZAAK-2026-1111')
        ->assertCanSeeTableRecords([$matching])
        ->assertCanNotSeeTableRecords([$other]);
});

test('it can filter batches by status', function () {
    $pending = Batch::factory()->pending()->create();
    $processed = Batch::factory()->processed()->create();

    Livewire::test(ListBatches::class)
        ->filterTable('status', 'pending')
        ->assertCanSeeTableRecords([$pending])
        ->assertCanNotSeeTableRecords([$processed]);
});

test('it orders zaak groups by their latest batch, descending by default', function () {
    [$zaakA, $zaakAOlder, $zaakB, $zaakC] = createBatchesForGroupOrdering();

    Livewire::test(ListBatches::class)
        ->assertCanSeeTableRecords([$zaakA, $zaakAOlder, $zaakB, $zaakC], inOrder: true);
});

test('it orders zaak groups by their latest batch ascending when the group direction is reversed', function () {
    [$zaakA, $zaakAOlder, $zaakB, $zaakC] = createBatchesForGroupOrdering();

    Livewire::test(ListBatches::class)
        ->set('tableGrouping', 'zaak_identificatie:asc')
        ->assertCanSeeTableRecords([$zaakC, $zaakB, $zaakA, $zaakAOlder], inOrder: true);
});

/**
 * Zaak A has both the newest and the oldest batch, so ordering groups by the
 * earliest batch instead of the latest one would put it in the wrong place.
 *
 * @return array{0: Batch, 1: Batch, 2: Batch, 3: Batch}
 */
function createBatchesForGroupOrdering(): array
{
    $zaakAOlder = Batch::factory()->create(['zaak_identificatie' => 'ZAAK-2026-1001', 'created_at' => now()->subDays(3)]);
    $zaakA = Batch::factory()->create(['zaak_identificatie' => 'ZAAK-2026-1001', 'created_at' => now()->subHour()]);
    $zaakB = Batch::factory()->create(['zaak_identificatie' => 'ZAAK-2026-1002', 'created_at' => now()->subDay()]);
    $zaakC = Batch::factory()->create(['zaak_identificatie' => 'ZAAK-2026-1003', 'created_at' => now()->subDays(2)]);

    return [$zaakA, $zaakAOlder, $zaakB, $zaakC];
}

// ─── View page ───────────────────────────────────────────────────────────────

test('it can render the view batch page', function () {
    $batch = Batch::factory()->create();

    Livewire::test(ViewBatch::class, ['record' => $batch->getRouteKey()])
        ->assertSuccessful();
});

// ─── Notifications relation manager ──────────────────────────────────────────

test('it shows notifications in the relation manager', function () {
    $batch = Batch::factory()->create(['zaak_identificatie' => 'ZAAK-2026-7777']);
    $notifications = Notification::factory()->count(2)->create([
        'batch_id' => $batch->id,
        'zaak_identificatie' => $batch->zaak_identificatie,
    ]);

    Livewire::test(NotificationsRelationManager::class, [
        'ownerRecord' => $batch,
        'pageClass' => ViewBatch::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords($notifications);
});

test('it shows create:zaak and create:resultaat notifications', function () {
    $batch = Batch::factory()->create(['zaak_identificatie' => 'ZAAK-2026-8888']);

    $zaakNotification = Notification::factory()->forZaak()->create([
        'batch_id' => $batch->id,
        'zaak_identificatie' => $batch->zaak_identificatie,
    ]);
    $resultaatNotification = Notification::factory()->forResultaat()->create([
        'batch_id' => $batch->id,
        'zaak_identificatie' => $batch->zaak_identificatie,
    ]);

    Livewire::test(NotificationsRelationManager::class, [
        'ownerRecord' => $batch,
        'pageClass' => ViewBatch::class,
    ])
        ->assertCanSeeTableRecords([$zaakNotification, $resultaatNotification]);
});

test('it lists the notifications of a batch in processing order', function () {
    $batch = Batch::factory()->create(['zaak_identificatie' => 'ZAAK-2026-8888']);
    $attributes = ['batch_id' => $batch->id, 'zaak_identificatie' => $batch->zaak_identificatie];

    // Created in the reverse order, so the order cannot come from the insert order.
    $resultaat = Notification::factory()->forResultaat()->create($attributes);
    $document = Notification::factory()->create([
        ...$attributes,
        'notification' => ['actie' => 'create', 'kanaal' => 'zaken', 'resource' => 'zaakinformatieobject'],
    ]);
    $zaak = Notification::factory()->forZaak()->create($attributes);

    $records = Livewire::test(NotificationsRelationManager::class, [
        'ownerRecord' => $batch,
        'pageClass' => ViewBatch::class,
    ])->instance()->getTableRecords();

    expect($records->pluck('id')->all())->toBe([$zaak->id, $document->id, $resultaat->id]);
});

test('it renders the relation manager for a processed batch with processed_at', function () {
    $batch = Batch::factory()->processed()->create();
    Notification::factory()->processed()->create([
        'batch_id' => $batch->id,
        'zaak_identificatie' => $batch->zaak_identificatie,
    ]);

    Livewire::test(NotificationsRelationManager::class, [
        'ownerRecord' => $batch,
        'pageClass' => ViewBatch::class,
    ])
        ->assertSuccessful();
});

// ─── Status, failed jobs and payload in the notifications of a batch ─────────

test('the notifications of a batch show their status, with a link to a failed job only when there is one', function () {
    $batch = Batch::factory()->create(['zaak_identificatie' => 'ZAAK-0001']);
    $failed = Notification::factory()->forZaak()->failed()->create(['batch_id' => $batch->id, 'zaak_identificatie' => 'ZAAK-0001']);
    $open = Notification::factory()->forResultaat()->create(['batch_id' => $batch->id, 'zaak_identificatie' => 'ZAAK-0001']);
    $processed = Notification::factory()->processed()->create(['batch_id' => $batch->id, 'zaak_identificatie' => 'ZAAK-0001']);

    Livewire::test(NotificationsRelationManager::class, [
        'ownerRecord' => $batch,
        'pageClass' => ViewBatch::class,
    ])
        ->assertTableColumnStateSet('status', NotificationStatus::Failed, $failed)
        ->assertTableColumnStateSet('status', NotificationStatus::Pending, $open)
        ->assertTableColumnStateSet('status', NotificationStatus::Processed, $processed)
        ->assertActionVisible(TestAction::make('failed_job')->table($failed))
        ->assertActionHasUrl(TestAction::make('failed_job')->table($failed), $failed->failedJobUrl())
        ->assertActionShouldOpenUrlInNewTab(TestAction::make('failed_job')->table($failed))
        ->assertActionHidden(TestAction::make('failed_job')->table($open))
        ->assertActionHidden(TestAction::make('failed_job')->table($processed));
});

test('the notifications of a batch have the same payload view as the notifications overview', function () {
    $batch = Batch::factory()->create(['zaak_identificatie' => 'ZAAK-0001']);
    $failed = Notification::factory()->forZaak()->failed()->create(['batch_id' => $batch->id, 'zaak_identificatie' => 'ZAAK-0001']);

    Livewire::test(NotificationsRelationManager::class, [
        'ownerRecord' => $batch,
        'pageClass' => ViewBatch::class,
    ])
        ->mountAction(TestAction::make('view_payload')->table($failed))
        ->assertHasNoActionErrors()
        ->assertMountedActionModalSee('Batch')
        ->assertMountedActionModalSee('Mislukt')
        ->assertMountedActionModalSee('ConnectionException: Connection timed out')
        ->assertMountedActionModalSee('Openen in de job monitor')
        ->assertMountedActionModalSee($failed->notification['resourceUrl'])
        ->assertMountedActionModalDontSee('https:\\/\\/', false);
});

test('the notifications of a batch show the status of each notification', function () {
    $batch = Batch::factory()->create(['zaak_identificatie' => 'ZAAK-0001']);
    Notification::factory()->forZaak()->failed()->create(['batch_id' => $batch->id, 'zaak_identificatie' => 'ZAAK-0001']);
    Notification::factory()->forResultaat()->create(['batch_id' => $batch->id, 'zaak_identificatie' => 'ZAAK-0001']);

    Livewire::test(NotificationsRelationManager::class, [
        'ownerRecord' => $batch,
        'pageClass' => ViewBatch::class,
    ])
        ->assertSee('Mislukt')
        ->assertSee('Nog niet verwerkt');
});

// ─── Layout of the batch page ────────────────────────────────────────────────

test('the batch details take the full width and the notifications are only listed in the relation manager', function () {
    $components = BatchInfolist::configure(Schema::make())->getComponents();

    expect($components)->toHaveCount(1)
        ->and($components[0])->toBeInstanceOf(Section::class)
        ->and($components[0]->getHeading())->toBe('Batch details')
        ->and($components[0]->getColumnSpan('default'))->toBe('full');
});

test('the batch page shows the notifications under one Dutch heading', function () {
    $batch = Batch::factory()->create(['zaak_identificatie' => 'ZAAK-0001']);
    Notification::factory()->forZaak()->create(['batch_id' => $batch->id, 'zaak_identificatie' => 'ZAAK-0001']);

    Livewire::test(ViewBatch::class, ['record' => $batch->getRouteKey()])
        ->assertSeeLivewire(NotificationsRelationManager::class)
        ->assertDontSeeText('Notifications');

    $relationManager = Livewire::test(NotificationsRelationManager::class, [
        'ownerRecord' => $batch,
        'pageClass' => ViewBatch::class,
    ])
        ->assertDontSeeText('Notifications');

    expect(substr_count(strip_tags($relationManager->html()), 'Notificaties'))->toBe(1);
});

test('the notifications of a batch show every column and the record actions as icon buttons', function () {
    $batch = Batch::factory()->create(['zaak_identificatie' => 'ZAAK-0001']);
    $failed = Notification::factory()->forZaak()->failed()->create(['batch_id' => $batch->id, 'zaak_identificatie' => 'ZAAK-0001']);

    $component = Livewire::test(NotificationsRelationManager::class, [
        'ownerRecord' => $batch,
        'pageClass' => ViewBatch::class,
    ]);

    foreach (['sort_order', 'actie_resource', 'aanmaakdatum', 'status', 'processed_at'] as $column) {
        $component->assertCanRenderTableColumn($column);
    }

    $component
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
