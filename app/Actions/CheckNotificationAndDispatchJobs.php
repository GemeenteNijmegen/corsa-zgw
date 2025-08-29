<?php

namespace App\Actions;

use Illuminate\Support\Arr;
use Woweb\Openzaak\Openzaak;

class CheckNotificationAndDispatchJobs
{
    private Openzaak $openzaak;

    public function __construct()
    {
        $this->openzaak = new Openzaak();
    }

    public function handle(array $notification): void
    {
        if (
            $notification['kanaal'] == 'zaken' &&
            $notification['actie'] == 'create' &&
            $notification['resource'] == 'status'
        ) {
            $this->handleCreateZaakStatus($notification);
        }
        // Check the type of notification and dispatch the appropriate job
    }

    private function handleCreateZaakStatus(array $notification): void
    {
        $ozZaak = $this->getZaakWithStatus($notification)->toArray();
        if (
            Arr::has($ozZaak, '_expand.status._expand.statustype.volgnummer') &&
            Arr::get($ozZaak, '_expand.status._expand.statustype.volgnummer') == 1
        ) {
            // Dispatch job chain


        }
    }

    private function getZaakWithStatus(array $notification)
    {
        $url = $notification['hoofdObject'] . '?' . http_build_query(['expand' => 'status,status.statustype']);
        return $this->openzaak->get($url);
    }
}
