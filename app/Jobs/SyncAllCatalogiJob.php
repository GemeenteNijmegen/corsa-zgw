<?php

namespace App\Jobs;

use App\Models\Catalogus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Woweb\Openzaak\Openzaak;

class SyncAllCatalogiJob implements ShouldQueue
{
    use Queueable;

    public function handle(Openzaak $openzaak): void
    {
        $zgwCatalogi = $openzaak->catalogi()->catalogussen()->getAll();

        $zgwUrls = $zgwCatalogi->pluck('url')->filter()->values();

        Log::info('SyncAllCatalogiJob fetched catalogi from ZGW', [
            'count' => $zgwUrls->count(),
        ]);

        // Create new catalogi that don't exist yet (inactive by default)
        foreach ($zgwCatalogi as $zgwCatalogus) {
            $url = $zgwCatalogus['url'] ?? null;
            if (! $url) {
                continue;
            }

            $existing = Catalogus::where('url', $url)->first();

            if (! $existing) {
                Catalogus::create([
                    'url' => $url,
                    'omschrijving' => $zgwCatalogus['naam'] ?? '',
                    'is_active' => false,
                ]);

                Log::info('SyncAllCatalogiJob created new catalogus', ['url' => $url]);
            }
        }

        // Deactivate catalogi that no longer exist in ZGW
        Catalogus::where('is_active', true)
            ->whereNotIn('url', $zgwUrls->all())
            ->each(function (Catalogus $catalogus) {
                $catalogus->update(['is_active' => false]);

                Log::warning('SyncAllCatalogiJob deactivated catalogus no longer in ZGW', [
                    'catalogus_id' => $catalogus->id,
                    'url' => $catalogus->url,
                ]);
            });

        // Dispatch zaaktype sync for all active catalogi
        $activeCatalogi = Catalogus::where('is_active', true)->get();

        Log::info('SyncAllCatalogiJob dispatching sync for active catalogi', [
            'count' => $activeCatalogi->count(),
        ]);

        foreach ($activeCatalogi as $catalogus) {
            SyncCatalogusJob::dispatch($catalogus);
        }
    }
}
