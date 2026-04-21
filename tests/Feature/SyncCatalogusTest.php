<?php

use App\Jobs\Sync\SyncCatalogus;
use App\Models\Catalogus;
use App\Models\ZaaktypeMapping;
use Woweb\Openzaak\Openzaak;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

test('it upserts zaaktype mappings from a response', function () {
    $catalogus = Catalogus::factory()->create([
        'url' => 'https://openzaak.example.com/catalogi/api/v1/catalogussen/abc-123',
    ]);

    $zaaktypeUrl = 'https://openzaak.example.com/catalogi/api/v1/zaaktypen/aaa-111';

    $openzaakMock = Mockery::mock(Openzaak::class);
    $openzaakMock->shouldReceive('get')
        ->once()
        ->with('https://openzaak.example.com/catalogi/api/v1/zaaktypen?catalogus='.urlencode($catalogus->url))
        ->andReturn(collect([
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
        ]));

    $job = new SyncCatalogus($catalogus);
    $job->handle($openzaakMock);

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

    $page1Url = 'https://openzaak.example.com/catalogi/api/v1/zaaktypen?catalogus='.urlencode($catalogus->url);
    $page2Url = 'https://openzaak.example.com/catalogi/api/v1/zaaktypen?page=2';

    $openzaakMock = Mockery::mock(Openzaak::class);
    $openzaakMock->shouldReceive('get')
        ->once()
        ->with($page1Url)
        ->andReturn(collect([
            'count' => 2,
            'next' => $page2Url,
            'results' => [['url' => 'https://openzaak.example.com/catalogi/api/v1/zaaktypen/aaa-111', 'identificatie' => 'ZT-001', 'omschrijving' => 'Type 1']],
        ]));

    $openzaakMock->shouldReceive('get')
        ->once()
        ->with($page2Url)
        ->andReturn(collect([
            'count' => 2,
            'next' => null,
            'results' => [['url' => 'https://openzaak.example.com/catalogi/api/v1/zaaktypen/bbb-222', 'identificatie' => 'ZT-002', 'omschrijving' => 'Type 2']],
        ]));

    $job = new SyncCatalogus($catalogus);
    $job->handle($openzaakMock);

    expect(ZaaktypeMapping::count())->toBe(2);
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

    $openzaakMock = Mockery::mock(Openzaak::class);
    $openzaakMock->shouldReceive('get')
        ->once()
        ->andReturn(collect([
            'count' => 1,
            'next' => null,
            'results' => [
                ['url' => $zaaktypeUrl, 'identificatie' => 'ZT-001', 'omschrijving' => 'Updated description'],
            ],
        ]));

    $job = new SyncCatalogus($catalogus);
    $job->handle($openzaakMock);

    $mapping = ZaaktypeMapping::where('zaaktype_url', $zaaktypeUrl)->first();
    expect($mapping->zaaktype_omschrijving)->toBe('Updated description')
        ->and($mapping->corsa_zaaktype_code)->toBe('MY_CODE')
        ->and($mapping->is_active)->toBeTrue();
});
