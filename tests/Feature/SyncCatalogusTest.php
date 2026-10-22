<?php

use App\Jobs\Sync\SyncCatalogus;
use App\Models\Catalogus;
use App\Models\ZaaktypeMapping;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

const ZAAKTYPEN_URL = 'https://openzaak.example.com/catalogi/api/v1/zaaktypen';

beforeEach(fn () => Http::preventStrayRequests());

function runSyncCatalogus(Catalogus $catalogus): void
{
    app()->call([new SyncCatalogus($catalogus), 'handle']);
}

/**
 * The query parameters of a faked request.
 */
function requestQuery(Request $request): array
{
    parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

    return $query;
}

test('it upserts zaaktype mappings from a response', function () {
    $catalogus = Catalogus::factory()->create([
        'url' => 'https://openzaak.example.com/catalogi/api/v1/catalogussen/abc-123',
    ]);

    $zaaktypeUrl = 'https://openzaak.example.com/catalogi/api/v1/zaaktypen/aaa-111';

    Http::fake([
        ZAAKTYPEN_URL.'*' => Http::response([
            'count' => 1,
            'next' => null,
            'previous' => null,
            'results' => [
                [
                    'url' => $zaaktypeUrl,
                    'identificatie' => 'ZT-001',
                    'omschrijving' => 'Test zaaktype',
                ],
            ],
        ]),
    ]);

    runSyncCatalogus($catalogus);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && strtok($request->url(), '?') === ZAAKTYPEN_URL
        && requestQuery($request) === ['catalogus' => $catalogus->url]);

    expect(ZaaktypeMapping::count())->toBe(1);

    $mapping = ZaaktypeMapping::first();
    expect($mapping->zaaktype_url)->toBe($zaaktypeUrl)
        ->and($mapping->zaaktype_identificatie)->toBe('ZT-001')
        ->and($mapping->zaaktype_omschrijving)->toBe('Test zaaktype')
        ->and($mapping->catalogus_id)->toBe($catalogus->id)
        ->and($mapping->is_active)->toBeFalse()
        ->and($mapping->corsa_zaaktype_code)->toBeNull();

    $catalogus->refresh();
    expect($catalogus->last_synced_at)->not->toBeNull();
});

test('it follows pagination next links', function () {
    $catalogus = Catalogus::factory()->create([
        'url' => 'https://openzaak.example.com/catalogi/api/v1/catalogussen/abc-123',
    ]);

    $page2Url = ZAAKTYPEN_URL.'?page=2';

    Http::fake(function (Request $request) use ($page2Url) {
        if ($request->url() === $page2Url) {
            return Http::response([
                'count' => 2,
                'next' => null,
                'results' => [['url' => 'https://openzaak.example.com/catalogi/api/v1/zaaktypen/bbb-222', 'identificatie' => 'ZT-002', 'omschrijving' => 'Type 2']],
            ]);
        }

        return Http::response([
            'count' => 2,
            'next' => $page2Url,
            'results' => [['url' => 'https://openzaak.example.com/catalogi/api/v1/zaaktypen/aaa-111', 'identificatie' => 'ZT-001', 'omschrijving' => 'Type 1']],
        ]);
    });

    runSyncCatalogus($catalogus);

    expect(ZaaktypeMapping::count())->toBe(2);
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request) => $request->url() === $page2Url);
});

test('it preserves existing corsa_zaaktype_code and is_active on re-sync', function () {
    $catalogus = Catalogus::factory()->create([
        'url' => 'https://openzaak.example.com/catalogi/api/v1/catalogussen/abc-123',
    ]);

    $zaaktypeUrl = 'https://openzaak.example.com/catalogi/api/v1/zaaktypen/aaa-111';

    ZaaktypeMapping::factory()->create([
        'catalogus_id' => $catalogus->id,
        'zaaktype_url' => $zaaktypeUrl,
        'zaaktype_omschrijving' => 'Old description',
        'corsa_zaaktype_code' => 'MY_CODE',
        'is_active' => true,
    ]);

    Http::fake([
        ZAAKTYPEN_URL.'*' => Http::response([
            'count' => 1,
            'next' => null,
            'results' => [
                ['url' => $zaaktypeUrl, 'identificatie' => 'ZT-001', 'omschrijving' => 'Updated description'],
            ],
        ]),
    ]);

    runSyncCatalogus($catalogus);

    $mapping = ZaaktypeMapping::where('zaaktype_url', $zaaktypeUrl)->first();
    expect($mapping->zaaktype_omschrijving)->toBe('Updated description')
        ->and($mapping->corsa_zaaktype_code)->toBe('MY_CODE')
        ->and($mapping->is_active)->toBeTrue();
});
