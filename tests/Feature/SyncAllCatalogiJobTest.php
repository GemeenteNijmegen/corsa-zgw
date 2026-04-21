<?php

use App\Jobs\SyncAllCatalogiJob;
use App\Jobs\SyncCatalogusJob;
use App\Models\Catalogus;
use Illuminate\Support\Facades\Queue;
use Woweb\Openzaak\Api\CatalogiApi;
use Woweb\Openzaak\Api\Endpoints\Catalogi\Catalogussen;
use Woweb\Openzaak\Openzaak;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

function mockOpenzaakWithCatalogi(array $catalogiData): Openzaak
{
    $catalogussenMock = Mockery::mock(Catalogussen::class);
    $catalogussenMock->shouldReceive('getAll')
        ->once()
        ->andReturn(collect($catalogiData));

    $catalogiApiMock = Mockery::mock(CatalogiApi::class);
    $catalogiApiMock->shouldReceive('catalogussen')
        ->once()
        ->andReturn($catalogussenMock);

    $openzaakMock = Mockery::mock(Openzaak::class);
    $openzaakMock->shouldReceive('catalogi')
        ->once()
        ->andReturn($catalogiApiMock);

    return $openzaakMock;
}

test('it creates new catalogi from ZGW that do not exist yet', function () {
    Queue::fake();

    $openzaakMock = mockOpenzaakWithCatalogi([
        ['url' => 'https://openzaak.example.com/catalogi/api/v1/catalogussen/aaa', 'naam' => 'Catalogus A'],
    ]);

    (new SyncAllCatalogiJob)->handle($openzaakMock);

    expect(Catalogus::count())->toBe(1);

    $catalogus = Catalogus::first();
    expect($catalogus->url)->toBe('https://openzaak.example.com/catalogi/api/v1/catalogussen/aaa')
        ->and($catalogus->omschrijving)->toBe('Catalogus A')
        ->and($catalogus->is_active)->toBeFalse();
});

test('it does not duplicate catalogi that already exist', function () {
    Queue::fake();

    Catalogus::factory()->create([
        'url' => 'https://openzaak.example.com/catalogi/api/v1/catalogussen/aaa',
        'omschrijving' => 'Catalogus A',
        'is_active' => false,
    ]);

    $openzaakMock = mockOpenzaakWithCatalogi([
        ['url' => 'https://openzaak.example.com/catalogi/api/v1/catalogussen/aaa', 'naam' => 'Catalogus A'],
    ]);

    (new SyncAllCatalogiJob)->handle($openzaakMock);

    expect(Catalogus::count())->toBe(1);
});

test('it deactivates active catalogi that no longer exist in ZGW', function () {
    Queue::fake();

    $gone = Catalogus::factory()->create([
        'url' => 'https://openzaak.example.com/catalogi/api/v1/catalogussen/gone',
        'is_active' => true,
    ]);

    $openzaakMock = mockOpenzaakWithCatalogi([
        ['url' => 'https://openzaak.example.com/catalogi/api/v1/catalogussen/aaa', 'naam' => 'Catalogus A'],
    ]);

    (new SyncAllCatalogiJob)->handle($openzaakMock);

    expect($gone->fresh()->is_active)->toBeFalse();
});

test('it does not touch inactive catalogi that are gone from ZGW', function () {
    Queue::fake();

    $inactive = Catalogus::factory()->inactive()->create([
        'url' => 'https://openzaak.example.com/catalogi/api/v1/catalogussen/gone',
    ]);

    $openzaakMock = mockOpenzaakWithCatalogi([]);

    (new SyncAllCatalogiJob)->handle($openzaakMock);

    // Still exists and still inactive — no change
    expect($inactive->fresh()->is_active)->toBeFalse();
});

test('it dispatches SyncCatalogusJob for each active catalogus', function () {
    Queue::fake();

    $active = Catalogus::factory()->create([
        'url' => 'https://openzaak.example.com/catalogi/api/v1/catalogussen/active',
        'is_active' => true,
    ]);

    $openzaakMock = mockOpenzaakWithCatalogi([
        ['url' => 'https://openzaak.example.com/catalogi/api/v1/catalogussen/active', 'naam' => 'Active'],
    ]);

    (new SyncAllCatalogiJob)->handle($openzaakMock);

    Queue::assertPushed(SyncCatalogusJob::class, fn ($job) => true);
});
