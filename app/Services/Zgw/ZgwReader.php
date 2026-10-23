<?php

namespace App\Services\Zgw;

use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Woweb\Zgw\Connection\ZgwConnection;
use Woweb\Zgw\Exceptions\ApiRequestException;
use Woweb\Zgw\Exceptions\DisallowedHostException;
use Woweb\Zgw\ZgwManager;

/**
 * Read-only access to the ZGW APIs, the only operations this application needs.
 *
 * Every request goes through the configured ZGW connection, which only sends requests to the
 * origins of the configured base URLs (plus any configured extra hosts). A URL on another origin
 * results in a DisallowedHostException before anything is sent.
 *
 * The connection is resolved on first use, so the application boots without a complete ZGW
 * configuration and a configuration error only surfaces when the APIs are actually called.
 */
class ZgwReader
{
    public function __construct(private readonly ZgwManager $manager) {}

    /**
     * Fetch a single resource by its full URL.
     *
     * @return array<string, mixed>
     *
     * @throws DisallowedHostException
     * @throws ApiRequestException
     */
    public function get(string $url): array
    {
        return $this->connection()->direct()->getByUrl($url);
    }

    /**
     * Fetch the raw body of a URL, such as the inhoud link of a document.
     *
     * @throws DisallowedHostException
     * @throws ApiRequestException on a 4xx or 5xx response
     */
    public function getRaw(string $url): string
    {
        return $this->connection()->direct()->getRawByUrl($url);
    }

    /**
     * The links between zaken and the given document. The Zaken API returns this list without
     * pagination.
     *
     * @return Collection<int, array<string, mixed>>
     *
     * @throws ApiRequestException
     */
    public function zaakinformatieobjecten(string $informatieobjectUrl): Collection
    {
        return $this->connection()->zaken()->zaakinformatieobjecten()
            ->index(['informatieobject' => $informatieobjectUrl])
            ->collect();
    }

    /**
     * All catalogussen, across every page.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function catalogussen(): Collection
    {
        return $this->connection()->catalogi()->catalogussen()->index()->collect();
    }

    /**
     * All zaaktypen of a catalogus. The pages are fetched while the collection is iterated.
     *
     * @return LazyCollection<int, array<string, mixed>>
     */
    public function zaaktypen(string $catalogusUrl): LazyCollection
    {
        return $this->connection()->catalogi()->zaaktypen()->index(['catalogus' => $catalogusUrl]);
    }

    /**
     * Whether a request to this URL would be allowed by the connection.
     */
    public function isAllowedUrl(string $url): bool
    {
        return $this->connection()->isUrlAllowed($url);
    }

    private function connection(): ZgwConnection
    {
        return $this->manager->connection();
    }
}
