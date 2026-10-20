<?php

use App\Jobs\Notifications\DispatchBatch;
use App\Jobs\Notifications\FlushExpiredBatches;
use App\Models\Batch;
use App\Services\BatchingService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(fn () => Log::spy());

test('the flush command dispatches FlushExpiredBatches on the configured queue', function () {
    Queue::fake();
    config(['batching.queue' => 'notifications']);

    $this->artisan('notifications:flush-expired-batches')
        ->expectsOutput('Batch processing job dispatched successfully.')
        ->assertSuccessful();

    Queue::assertPushedOn('notifications', FlushExpiredBatches::class);
    Queue::assertPushed(FlushExpiredBatches::class, 1);
});

test('FlushExpiredBatches dispatches one DispatchBatch per pending batch without an active timer', function () {
    Queue::fake();
    config(['batching.queue' => 'notifications']);

    $batchingService = app(BatchingService::class);

    $expired = Batch::factory()->pending()->count(2)->create();
    $waiting = Batch::factory()->pending()->create();
    $batchingService->resetTimer($waiting);
    Batch::factory()->processing()->create();
    Batch::factory()->processed()->create();

    app()->call([new FlushExpiredBatches, 'handle']);

    Queue::assertPushed(DispatchBatch::class, 2);
    foreach ($expired as $batch) {
        Queue::assertPushedOn('notifications', DispatchBatch::class, fn (DispatchBatch $job) => $job->tags()[0] === "batch:{$batch->id}");
    }
    Queue::assertNotPushed(DispatchBatch::class, fn (DispatchBatch $job) => $job->tags()[0] === "batch:{$waiting->id}");
});

test('DispatchBatch processes a fresh copy of the batch', function () {
    $batch = Batch::factory()->pending()->create();
    $staleCopy = Batch::find($batch->id);
    $batch->update(['status' => 'changed-after-dispatch']);

    $this->mock(BatchingService::class)
        ->shouldReceive('processBatch')
        ->once()
        ->withArgs(fn (Batch $received) => $received->is($batch)
            && $received !== $staleCopy
            && $received->status === 'changed-after-dispatch');

    $job = new DispatchBatch($staleCopy);
    app()->call([$job, 'handle']);

    expect($job->displayName())->toBe("Dispatch Batch {$batch->id} / {$batch->zaak_identificatie}");
});
