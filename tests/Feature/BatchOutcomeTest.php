<?php

use App\Filament\Resources\Batches\Pages\ListBatches;
use App\Filament\Resources\Batches\Pages\ViewBatch;
use App\Models\Batch;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

/**
 * A processed batch with one failed notification, and the notification after it in the chain that never ran.
 */
function batchWithFailedNotification(string $zaak = 'ZAAK-0001'): Batch
{
    $batch = Batch::factory()->processed()->create(['zaak_identificatie' => $zaak]);
    Notification::factory()->forZaak()->processed()->create(['batch_id' => $batch->id, 'zaak_identificatie' => $zaak]);
    Notification::factory()->failed()->create(['batch_id' => $batch->id, 'zaak_identificatie' => $zaak]);

    return $batch;
}

/**
 * A processed batch with a notification that is not processed, not failed and not ignored.
 */
function processedBatchWithOpenNotification(string $zaak = 'ZAAK-0002'): Batch
{
    $batch = Batch::factory()->processed()->create(['zaak_identificatie' => $zaak]);
    Notification::factory()->forZaak()->processed()->create(['batch_id' => $batch->id, 'zaak_identificatie' => $zaak]);
    Notification::factory()->forResultaat()->create(['batch_id' => $batch->id, 'zaak_identificatie' => $zaak, 'processed' => false]);

    return $batch;
}

/**
 * A processed batch where every notification is processed, apart from an ignored one.
 */
function completeBatch(string $zaak = 'ZAAK-0003'): Batch
{
    $batch = Batch::factory()->processed()->create(['zaak_identificatie' => $zaak]);
    Notification::factory()->forZaak()->processed()->create(['batch_id' => $batch->id, 'zaak_identificatie' => $zaak]);
    Notification::factory()->create([
        'batch_id' => $batch->id,
        'zaak_identificatie' => $zaak,
        'processed' => false,
        'ignored_at' => now(),
        'ignored_reason' => App\Enums\NotificationIgnoreReason::UnknownZaaktype,
    ]);

    return $batch;
}

// ─── Model ───────────────────────────────────────────────────────────────────

test('a batch counts its failed notifications and the ones not processed yet', function () {
    $failed = batchWithFailedNotification();
    $open = processedBatchWithOpenNotification();
    $complete = completeBatch();
    $pending = Batch::factory()->pending()->create(['zaak_identificatie' => 'ZAAK-0004']);
    Notification::factory()->create(['batch_id' => $pending->id, 'zaak_identificatie' => 'ZAAK-0004', 'processed' => false]);

    expect($failed->failedNotificationsCount())->toBe(1)
        ->and($failed->isIncomplete())->toBeFalse()
        ->and($open->failedNotificationsCount())->toBe(0)
        ->and($open->isIncomplete())->toBeTrue()
        ->and($complete->failedNotificationsCount())->toBe(0)
        ->and($complete->isIncomplete())->toBeFalse()
        // A batch that is not processed yet is still waiting, so its open notifications are not a signal.
        ->and($pending->isIncomplete())->toBeFalse();

    $counted = Batch::query()->withNotificationOutcome()->findOrFail($open->id);
    expect($counted->getAttributes())->toHaveKeys(['failed_notifications_count', 'not_processed_notifications_count'])
        ->and($counted->notProcessedNotificationsCount())->toBe(1);
});

// ─── Overview ────────────────────────────────────────────────────────────────

test('the overview shows the failed notifications and the incomplete batches, and nothing for a batch that went well', function () {
    $failed = batchWithFailedNotification();
    $open = processedBatchWithOpenNotification();
    $complete = completeBatch();

    Livewire::test(ListBatches::class)
        ->assertTableColumnStateSet('failed_notifications_count', 1, $failed)
        ->assertTableColumnStateSet('incomplete', null, $failed)
        ->assertTableColumnStateSet('failed_notifications_count', null, $open)
        ->assertTableColumnStateSet('incomplete', 'Niet alles verwerkt', $open)
        ->assertTableColumnStateSet('failed_notifications_count', null, $complete)
        ->assertTableColumnStateSet('incomplete', null, $complete)
        ->assertSee('Niet alles verwerkt');
});

test('the overview can be filtered on batches with failed notifications', function () {
    $failed = batchWithFailedNotification();
    $open = processedBatchWithOpenNotification();
    $complete = completeBatch();

    Livewire::test(ListBatches::class)
        ->filterTable('failed_notifications')
        ->assertCanSeeTableRecords([$failed])
        ->assertCanNotSeeTableRecords([$open, $complete]);
});

test('the overview can be filtered on incomplete batches', function () {
    $failed = batchWithFailedNotification();
    $open = processedBatchWithOpenNotification();
    $complete = completeBatch();
    // Waiting for its timer and running its chain: open notifications are expected there.
    $waiting = Batch::factory()->pending()->create(['zaak_identificatie' => 'ZAAK-0004']);
    $running = Batch::factory()->create(['zaak_identificatie' => 'ZAAK-0005', 'status' => 'processing', 'locked_at' => now()]);
    foreach ([$waiting, $running] as $batch) {
        Notification::factory()->forZaak()->create(['batch_id' => $batch->id, 'zaak_identificatie' => $batch->zaak_identificatie]);
    }

    Livewire::test(ListBatches::class)
        ->assertTableColumnStateSet('incomplete', null, $waiting)
        ->assertTableColumnStateSet('incomplete', null, $running)
        ->filterTable('incomplete')
        ->assertCanSeeTableRecords([$open])
        ->assertCanNotSeeTableRecords([$failed, $complete, $waiting, $running]);
});

test('a running batch is blue, not orange like "Niet alles verwerkt", and failed counts can be sorted', function () {
    $running = Batch::factory()->create(['zaak_identificatie' => 'ZAAK-0005', 'status' => 'processing', 'locked_at' => now()]);

    $component = Livewire::test(ListBatches::class);
    $table = $component->instance()->getTable();

    expect($table->getColumn('status')->record($running)->getColor('processing'))->toBe('info')
        ->and($table->getColumn('incomplete')->getColor('Niet alles verwerkt'))->toBe('warning')
        ->and($table->getColumn('failed_notifications_count')->isSortable())->toBeTrue();
});

test('an ignored notification in a batch shows as ignored, not as pending or failed', function () {
    $batch = Batch::factory()->processed()->create(['zaak_identificatie' => 'ZAAK-0001']);
    $ignored = Notification::factory()->failed()->create([
        'batch_id' => $batch->id,
        'zaak_identificatie' => 'ZAAK-0001',
        'ignored_at' => now(),
        'ignored_reason' => App\Enums\NotificationIgnoreReason::UnsupportedAction,
    ]);

    expect($ignored->status())->toBe(App\Enums\NotificationStatus::Ignored)
        ->and($batch->failedNotificationsCount())->toBe(0)
        ->and($batch->isIncomplete())->toBeFalse();
});

test('the overview does not query per row for the signals', function () {
    batchWithFailedNotification('ZAAK-0001');

    DB::enableQueryLog();
    Livewire::test(ListBatches::class);
    $queriesForOneBatch = count(DB::getQueryLog());
    DB::flushQueryLog();

    foreach (range(2, 7) as $number) {
        processedBatchWithOpenNotification(sprintf('ZAAK-%04d', $number));
    }

    DB::flushQueryLog();
    Livewire::test(ListBatches::class);
    $queriesForSevenBatches = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queriesForSevenBatches)->toBe($queriesForOneBatch);
});

// ─── Batch page ──────────────────────────────────────────────────────────────

test('the batch page shows the failed notifications', function () {
    $batch = batchWithFailedNotification();

    Livewire::test(ViewBatch::class, ['record' => $batch->getRouteKey()])
        ->assertSee('Mislukte notificaties')
        ->assertDontSee('Niet alles verwerkt');
});

test('the batch page shows that not everything in a processed batch is processed', function () {
    $batch = processedBatchWithOpenNotification();

    Livewire::test(ViewBatch::class, ['record' => $batch->getRouteKey()])
        ->assertSee('Niet alles verwerkt')
        ->assertSee('De batch is afgerond, maar 1 notificatie(s) erin zijn niet verwerkt, niet mislukt en niet genegeerd')
        ->assertDontSee('Mislukte notificaties');
});

test('the batch page shows no signal for a batch that went well', function () {
    $batch = completeBatch();

    Livewire::test(ViewBatch::class, ['record' => $batch->getRouteKey()])
        ->assertDontSee('Mislukte notificaties')
        ->assertDontSee('Niet alles verwerkt');
});
