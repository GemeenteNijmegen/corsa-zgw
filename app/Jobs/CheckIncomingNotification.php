<?php

namespace App\Jobs;

use App\Models\Notification;
use App\Services\BatchingService;
use App\ValueObjects\OpenNotification;
use App\ValueObjects\ZGW\Zaak;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Woweb\Openzaak\Openzaak;

class CheckIncomingNotification implements ShouldQueue
{
    use Queueable;

    public function __construct(private OpenNotification $opennotification) {}

    /**
     * Execute the job.
     */
    public function handle(Openzaak $openzaak, BatchingService $batchingService): void
    {
        if ($this->opennotification->kanaal != 'zaken') {
            // Don't process the notification
            return;
        }

        // get the zaak from the hoofdobject URL
        $zaak = new Zaak(...$openzaak->get($this->opennotification->hoofdObject)->toArray());

        Log::info('Received notification', [
            'zaak_identificatie' => $zaak->identificatie,
            'actie' => $this->opennotification->actie,
        ]);

        // Get or create batch for this case
        $batch = $batchingService->getOrCreateBatch($zaak->identificatie);

        // Create the notification record in the database
        $notification = Notification::create([
            'zaak_identificatie' => $zaak->identificatie,
            'notification' => $this->opennotification->toArray(),
            'processed' => false,
        ]);

        // Add notification to batch and reset timer
        $batchingService->addNotificationToBatch($notification, $batch);

        Log::info('Notification added to batch', [
            'notification_id' => $notification->id,
            'batch_id' => $batch->id,
            'zaak_identificatie' => $zaak->identificatie,
        ]);
    }
}
