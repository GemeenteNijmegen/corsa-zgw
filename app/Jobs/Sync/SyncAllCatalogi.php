<?php

namespace App\Jobs\Sync;

use App\Models\Catalogus;
use App\Services\Zgw\ZgwReader;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncAllCatalogi implements ShouldQueue
{
    use Queueable;

    public function handle(ZgwReader $zgw): void
    {
        $zgwCatalogi = $zgw->catalogussen();

        $zgwUrls = $zgwCatalogi->pluck('url')->filter()->values();

        Log::info('SyncAllCatalogi fetched catalogi from ZGW', [
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

                Log::info('SyncAllCatalogi created new catalogus', ['url' => $url]);
            }
        }

        // Deactivate catalogi that no longer exist in ZGW
        Catalogus::where('is_active', true)
            ->whereNotIn('url', $zgwUrls->all())
            ->each(function (Catalogus $catalogus) {
                $catalogus->update(['is_active' => false]);

                Log::warning('SyncAllCatalogi deactivated catalogus no longer in ZGW', [
                    'catalogus_id' => $catalogus->id,
                    'url' => $catalogus->url,
                ]);
            });

        // Dispatch zaaktype sync for all active catalogi
        $activeCatalogi = Catalogus::where('is_active', true)->get();

        Log::info('SyncAllCatalogi dispatching sync for active catalogi', [
            'count' => $activeCatalogi->count(),
        ]);

        foreach ($activeCatalogi as $catalogus) {
            SyncCatalogus::dispatch($catalogus);
        }
    }
}
