<?php

namespace App\Jobs\Sync;

use App\Models\Catalogus;
use App\Models\ZaaktypeMapping;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Woweb\Openzaak\Openzaak;

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

    public function handle(Openzaak $openzaak): void
    {
        Log::info('SyncCatalogus started', [
            'catalogus_id' => $this->catalogus->id,
            'catalogus_url' => $this->catalogus->url,
        ]);

        $zaaktypenUrl = $this->buildZaaktypenUrl($this->catalogus->url);
        $synced = 0;

        while ($zaaktypenUrl !== null) {
            $response = $openzaak->get($zaaktypenUrl)->toArray();

            $results = $response['results'] ?? [];

            foreach ($results as $zaaktype) {
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

            $zaaktypenUrl = $response['next'] ?? null;
        }

        $this->catalogus->update(['last_synced_at' => now()]);

        Log::info('SyncCatalogus completed', [
            'catalogus_id' => $this->catalogus->id,
            'synced_count' => $synced,
        ]);
    }

    /**
     * Derive the zaaktypen list URL from the catalogus URL.
     *
     * Replaces /catalogussen/{uuid} with /zaaktypen?catalogus={catalogus_url}
     */
    private function buildZaaktypenUrl(string $catalogusUrl): string
    {
        $base = preg_replace('#/catalogussen/[^/?]+$#', '', $catalogusUrl);

        return $base.'/zaaktypen?catalogus='.urlencode($catalogusUrl);
    }
}
