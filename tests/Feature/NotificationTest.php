<?php

use App\Jobs\CheckIncomingNotification;
use App\ValueObjects\OpenNotification;
use Illuminate\Support\Facades\Queue;
use Woweb\Openzaak\Openzaak;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\Notification;

pest()->use(RefreshDatabase::class);

test('that incoming notification jobs are queued', function (): void {

    $notification = new OpenNotification(
      'create',
      'zaken',
      'zaak',
      'https://mijn-services.accp.nijmegen.nl/open-zaak/zaken/api/v1/zaken/c27e4634-cddf-4c5a-b567-375177d7f854',
      'https://mijn-services.accp.nijmegen.nl/open-zaak/zaken/api/v1/zaken/c27e4634-cddf-4c5a-b567-375177d7f854',
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
        
    $notification = new OpenNotification(
      'create',
      'zaken',
      'zaak',
      'https://mijn-services.accp.nijmegen.nl/open-zaak/zaken/api/v1/zaken/c27e4634-cddf-4c5a-b567-375177d7f854',
      'https://mijn-services.accp.nijmegen.nl/open-zaak/zaken/api/v1/zaken/c27e4634-cddf-4c5a-b567-375177d7f854',
      '2025-11-28T10:25:19.741Z'
    );
    Queue::fake();
    // Dispatch the job
    $job = new CheckIncomingNotification($notification);
    $result = $job->handle((app(Openzaak::class)));

    $this->assertDatabaseHas('notifications', [
        'zaak_identificatie' => 'ZAAK-2025-0000000171'
      ]);
});
