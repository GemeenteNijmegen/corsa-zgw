<?php

use App\Filament\Resources\Batches\Pages\ListBatches;
use App\Filament\Resources\Batches\Pages\ViewBatch;
use App\Models\Batch;
use App\Models\Notification;
use App\Models\User;
use Filament\Infolists\Components\TextEntry;
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

// ─── Status ──────────────────────────────────────────────────────────────────

/**
 * The status entry of the batch page, bound to the given batch.
 */
function batchPageStatusEntry(Batch $batch): TextEntry
{
    $page = Livewire::test(ViewBatch::class, ['record' => $batch->getRouteKey()])->instance();

    return collect($page->getSchema('infolist')->getFlatComponents())
        ->first(fn ($component): bool => $component instanceof TextEntry && $component->getName() === 'status');
}

test('the overview shows each status with its Dutch label, and a failed batch in red', function () {
    $pending = Batch::factory()->pending()->create(['zaak_identificatie' => 'ZAAK-0001']);
    $processing = Batch::factory()->processing()->create(['zaak_identificatie' => 'ZAAK-0002']);
    $processed = Batch::factory()->processed()->create(['zaak_identificatie' => 'ZAAK-0003']);
    $failed = Batch::factory()->failed()->create(['zaak_identificatie' => 'ZAAK-0004']);

    $component = Livewire::test(ListBatches::class)
        ->assertTableColumnFormattedStateSet('status', 'Wachtend', $pending)
        ->assertTableColumnFormattedStateSet('status', 'In verwerking', $processing)
        ->assertTableColumnFormattedStateSet('status', 'Verwerkt', $processed)
        ->assertTableColumnFormattedStateSet('status', 'Mislukt', $failed);
    $column = $component->instance()->getTable()->getColumn('status');

    expect($column->record($pending)->getColor('pending'))->toBe('gray')
        ->and($column->record($processing)->getColor('processing'))->toBe('info')
        ->and($column->record($processed)->getColor('processed'))->toBe('success')
        ->and($column->record($failed)->getColor('failed'))->toBe('danger');
});

test('the overview can be filtered on failed batches, with the same Dutch labels as the badge', function () {
    $processing = Batch::factory()->processing()->create(['zaak_identificatie' => 'ZAAK-0001']);
    $processed = Batch::factory()->processed()->create(['zaak_identificatie' => 'ZAAK-0002']);
    $failed = Batch::factory()->failed()->create(['zaak_identificatie' => 'ZAAK-0003']);

    $component = Livewire::test(ListBatches::class);

    expect($component->instance()->getTable()->getFilter('status')->getOptions())->toBe([
        'pending' => 'Wachtend',
        'processing' => 'In verwerking',
        'processed' => 'Verwerkt',
        'failed' => 'Mislukt',
    ]);

    $component->filterTable('status', 'failed')
        ->assertCanSeeTableRecords([$failed])
        ->assertCanNotSeeTableRecords([$processing, $processed]);
});

test('the batch page shows the status of a failed batch as a red badge with a Dutch label', function () {
    $batch = batchWithFailedNotification();
    $batch->update(['status' => 'failed', 'processed_at' => null]);
    $entry = batchPageStatusEntry($batch);

    expect($entry->formatState('failed'))->toBe('Mislukt')
        ->and($entry->getColor('failed'))->toBe('danger')
        ->and($entry->formatState('processing'))->toBe('In verwerking')
        ->and($entry->getColor('processing'))->toBe('info');

    Livewire::test(ViewBatch::class, ['record' => $batch->getRouteKey()])
        ->assertSee('Mislukt')
        ->assertSee('Mislukte notificaties')
        ->assertDontSee('Niet alles verwerkt')
        ->assertDontSee('Lang in verwerking');
});

test('"Niet alles verwerkt" stays for processed batches only, also for a failed batch with open notifications', function () {
    $batch = batchWithFailedNotification();
    Notification::factory()->create(['batch_id' => $batch->id, 'zaak_identificatie' => 'ZAAK-0001', 'processed' => false]);
    $batch->update(['status' => 'failed', 'processed_at' => null]);

    Livewire::test(ListBatches::class)
        ->assertTableColumnStateSet('failed_notifications_count', 1, $batch)
        ->assertTableColumnStateSet('incomplete', null, $batch);
});

// ─── Lang in verwerking ──────────────────────────────────────────────────────

/**
 * A batch that was locked the given number of minutes ago and is still processing, with one open notification.
 */
function processingBatchLockedMinutesAgo(int $minutes, string $zaak): Batch
{
    $batch = Batch::factory()->processing()->create(['zaak_identificatie' => $zaak, 'locked_at' => now()->subMinutes($minutes)]);
    Notification::factory()->forZaak()->create(['batch_id' => $batch->id, 'zaak_identificatie' => $zaak, 'processed' => false]);

    return $batch;
}

test('a batch that is processing for longer than the threshold without a failed notification is taking long', function () {
    config(['batching.stuck_after_minutes' => 30]);
    $recent = processingBatchLockedMinutesAgo(29, 'ZAAK-0001');
    $long = processingBatchLockedMinutesAgo(31, 'ZAAK-0002');
    $failed = processingBatchLockedMinutesAgo(31, 'ZAAK-0003');
    Notification::factory()->failed()->create(['batch_id' => $failed->id, 'zaak_identificatie' => 'ZAAK-0003']);
    $processed = Batch::factory()->processed()->create(['zaak_identificatie' => 'ZAAK-0004', 'locked_at' => now()->subHour()]);

    expect($recent->isStuck())->toBeFalse()
        ->and($long->isStuck())->toBeTrue()
        // A failed notification is its own signal; the chain stopped there.
        ->and($failed->isStuck())->toBeFalse()
        ->and($processed->isStuck())->toBeFalse()
        ->and(Batch::query()->stuck()->pluck('id')->all())->toBe([$long->id]);

    Livewire::test(ListBatches::class)
        ->assertTableColumnStateSet('stuck', null, $recent)
        ->assertTableColumnStateSet('stuck', 'Lang in verwerking', $long)
        ->assertTableColumnStateSet('stuck', null, $failed)
        ->assertTableColumnStateSet('stuck', null, $processed)
        ->assertSee('Lang in verwerking');
});

test('the threshold for taking long comes from the configuration', function () {
    $batch = processingBatchLockedMinutesAgo(45, 'ZAAK-0001');

    config(['batching.stuck_after_minutes' => 60]);
    expect($batch->isStuck())->toBeFalse()
        ->and(Batch::query()->stuck()->count())->toBe(0);

    config(['batching.stuck_after_minutes' => 40]);
    expect($batch->isStuck())->toBeTrue()
        ->and(Batch::query()->stuck()->count())->toBe(1);
});

test('the overview can be filtered on batches that take long', function () {
    config(['batching.stuck_after_minutes' => 30]);
    $recent = processingBatchLockedMinutesAgo(5, 'ZAAK-0001');
    $long = processingBatchLockedMinutesAgo(90, 'ZAAK-0002');
    $failed = Batch::factory()->failed()->create(['zaak_identificatie' => 'ZAAK-0003', 'locked_at' => now()->subHours(2)]);
    $open = processedBatchWithOpenNotification('ZAAK-0004');

    Livewire::test(ListBatches::class)
        ->filterTable('stuck')
        ->assertCanSeeTableRecords([$long])
        ->assertCanNotSeeTableRecords([$recent, $failed, $open]);
});

test('"Lang in verwerking" is orange with a clock, so it is told apart from "Niet alles verwerkt"', function () {
    $table = Livewire::test(ListBatches::class)->instance()->getTable();

    expect($table->getColumn('stuck')->getColor('Lang in verwerking'))->toBe('warning')
        ->and($table->getColumn('stuck')->getIcon('Lang in verwerking'))->toBe('heroicon-o-clock')
        ->and($table->getColumn('incomplete')->getIcon('Niet alles verwerkt'))->toBe('heroicon-o-exclamation-triangle');
});

test('the batch page shows that a batch takes long, and not for a batch within the threshold', function () {
    config(['batching.stuck_after_minutes' => 30]);
    $long = processingBatchLockedMinutesAgo(31, 'ZAAK-0001');
    $recent = processingBatchLockedMinutesAgo(29, 'ZAAK-0002');

    Livewire::test(ViewBatch::class, ['record' => $long->getRouteKey()])
        ->assertSee('Lang in verwerking')
        ->assertSee('De batch is al langer dan 30 minuten in verwerking zonder mislukte notificatie.')
        ->assertDontSee('Niet alles verwerkt');

    Livewire::test(ViewBatch::class, ['record' => $recent->getRouteKey()])
        ->assertDontSee('Lang in verwerking');
});

test('the overview does not query per row for taking long', function () {
    processingBatchLockedMinutesAgo(31, 'ZAAK-0001');

    DB::enableQueryLog();
    Livewire::test(ListBatches::class);
    $queriesForOneBatch = count(DB::getQueryLog());
    DB::flushQueryLog();

    foreach (range(2, 7) as $number) {
        processingBatchLockedMinutesAgo(31 + $number, sprintf('ZAAK-%04d', $number));
    }

    DB::flushQueryLog();
    Livewire::test(ListBatches::class);
    $queriesForSevenBatches = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queriesForSevenBatches)->toBe($queriesForOneBatch);
});
