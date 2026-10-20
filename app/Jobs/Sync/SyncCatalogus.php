<?php

namespace App\Jobs\Sync;

use App\Models\Catalogus;
use App\Models\ZaaktypeMapping;
use App\Services\Zgw\ZgwReader;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncCatalogus implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Catalogus $catalogus) {}

    public function displayName(): string
    {
        return "Sync Catalogus {$this->catalogus->url}";
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return ["catalogus:{$this->catalogus->id}"];
    }

    public function handle(ZgwReader $zgw): void
    {
        Log::info('SyncCatalogus started', [
            'catalogus_id' => $this->catalogus->id,
            'catalogus_url' => $this->catalogus->url,
        ]);

        $synced = 0;

        foreach ($zgw->zaaktypen($this->catalogus->url) as $zaaktype) {
            $url = $zaaktype['url'] ?? null;
            if (! $url) {
                continue;
            }

            ZaaktypeMapping::updateOrCreate(
                ['zaaktype_url' => $url],
                [
                    'catalogus_id' => $this->catalogus->id,
                    'zaaktype_identificatie' => $zaaktype['identificatie'] ?? '',
                    'zaaktype_omschrijving' => $zaaktype['omschrijving'] ?? '',
                    'synced_at' => now(),
                    // Preserve existing corsa_zaaktype_code and is_active on re-sync
                ]
            );

            $synced++;
        }

        $this->catalogus->update(['last_synced_at' => now()]);

        Log::info('SyncCatalogus completed', [
            'catalogus_id' => $this->catalogus->id,
            'synced_count' => $synced,
        ]);
    }
}
