<?php

use App\Jobs\CheckIncomingNotification;
use App\ValueObjects\OpenNotification;
use Illuminate\Support\Facades\Queue;
use Woweb\Openzaak\Openzaak;
use Illuminate\Foundation\Testing\RefreshDatabase;
// use App\Models\Notification;
use Mockery\MockInterface;

pest()->use(RefreshDatabase::class);

test('that incoming notification jobs are queued', function (): void {

    $notification = new OpenNotification(
      'create',
      'zaken',
      'zaak',
      'http://example.com/zaken/api/v1/zaken/c27e4634-cddf-4c5a-b567-375177d7f854',
      'http://example.com/zaken/api/v1/zaken/c27e4634-cddf-4c5a-b567-375177d7f854',
      '2025-11-28T10:25:19.741Z'
    );
    Queue::fake();
    // Dispatch the job
    $job = new CheckIncomingNotification($notification);
    dispatch($job);
    // Assert the job was pushed to the queue
    Queue::assertPushed(CheckIncomingNotification::class);
});


test('that incoming notification from random channels are ignored', function (): void {
  $this->partialMock(Openzaak::class, function(MockInterface $mock) {
    $mock->shouldReceive('get')->once()->andReturn(collect(['','','ZAAK-TEST','','','','','','','','',[]]));
  });
    $notification = new OpenNotification(
      'create',
      'zaken',
      'zaak',
      'http://example.com/zaken/api/v1/zaken/c27e4634-cddf-4c5a-b567-375177d7f854',
      'http://example.com/zaken/api/v1/zaken/c27e4634-cddf-4c5a-b567-375177d7f854',
      '2025-11-28T10:25:19.741Z'
    );
    Queue::fake();
    // Dispatch the job
    $job = new CheckIncomingNotification($notification);
    $job->handle((app(Openzaak::class)));

    $this->assertDatabaseHas('notifications', [
        'zaak_identificatie' => 'ZAAK-TEST'
      ]);
});
