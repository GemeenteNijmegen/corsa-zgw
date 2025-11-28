<?php

namespace App\Jobs;

use App\Models\Notification;
use App\ValueObjects\OpenNotification;
use App\ValueObjects\ZGW\Zaak;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Woweb\Openzaak\Openzaak;

class CheckIncomingNotification implements ShouldQueue
{
    use Queueable;

    public function __construct(private OpenNotification $opennotification) {}

    /**
     * Execute the job.
     */
    public function handle(Openzaak $openzaak): void
    {
        if ($this->opennotification->kanaal != 'zaken') {
            // Don't process the notification
            return;
        }

        // get the zaak from the hoofdobject URL
        $zaak = new Zaak(...$openzaak->get($this->opennotification->hoofdObject)->toArray());

        // create the notification record in the database
        Notification::create([
            'zaak_identificatie' => $zaak->identificatie,
            'notification' => $this->opennotification->toArray(),
            'processed' => false,
        ]);

    }
}
