<?php

namespace App\Jobs\Notifications;

use App\Models\Notification;
use App\Models\ZaaktypeMapping;
use App\Services\BatchingService;
use App\Services\Zgw\ZgwReader;
use App\ValueObjects\OpenNotification;
use App\ValueObjects\ZGW\Zaak;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Woweb\Zgw\Exceptions\DisallowedHostException;

class IngestNotification implements ShouldQueue
{
    use Queueable;

    public function __construct(private OpenNotification $opennotification) {}

    public function displayName(): string
    {
        return "Ingest {$this->opennotification->actie}:{$this->opennotification->resource}";
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return ["action:{$this->opennotification->actie}:{$this->opennotification->resource}"];
    }

    /**
     * Execute the job.
     */
    public function handle(ZgwReader $zgw, BatchingService $batchingService): void
    {
        if ($this->opennotification->kanaal != 'zaken') {
            // Don't process the notification
            return;
        }

        // get the zaak from the hoofdobject URL
        try {
            $zaak = new Zaak(...$zgw->get($this->opennotification->hoofdObject));
        } catch (DisallowedHostException $e) {
            // A URL outside the configured ZGW hosts will never succeed, so fail without a retry.
            Log::warning('Notification refers to a host that is not a configured ZGW host', [
                'actie' => $this->opennotification->actie,
                'resource' => $this->opennotification->resource,
                'reason' => $e->getMessage(),
            ]);

            $this->fail($e);

            return;
        }

        if (! ZaaktypeMapping::where('zaaktype_url', $zaak->zaaktype)->where('is_active', true)->exists()) {
            Log::warning('Zaaktype not active or unknown, ignoring notification', [
                'zaaktype_url' => $zaak->zaaktype,
                'zaak_identificatie' => $zaak->identificatie,
                'actie' => $this->opennotification->actie,
            ]);

            return;
        }

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
