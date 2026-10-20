<?php

use App\Jobs\Sync\SyncAllCatalogi;
use App\Jobs\Sync\SyncCatalogus;
use App\Models\Catalogus;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

const CATALOGUSSEN_URL = 'https://openzaak.example.com/catalogi/api/v1/catalogussen';

beforeEach(fn () => Http::preventStrayRequests());

/**
 * Fake the catalogussen list endpoint with a single page of results.
 */
function fakeCatalogussen(array $catalogiData): void
{
    Http::fake([
        CATALOGUSSEN_URL.'*' => Http::response([
            'count' => count($catalogiData),
            'next' => null,
            'previous' => null,
            'results' => $catalogiData,
        ]),
    ]);
}

function runSyncAllCatalogi(): void
{
    app()->call([new SyncAllCatalogi, 'handle']);
}

test('it creates new catalogi from ZGW that do not exist yet', function () {
    Queue::fake();

    fakeCatalogussen([
        ['url' => 'https://openzaak.example.com/catalogi/api/v1/catalogussen/aaa', 'naam' => 'Catalogus A'],
    ]);

    runSyncAllCatalogi();

    expect(Catalogus::count())->toBe(1);

    $catalogus = Catalogus::first();
    expect($catalogus->url)->toBe('https://openzaak.example.com/catalogi/api/v1/catalogussen/aaa')
        ->and($catalogus->omschrijving)->toBe('Catalogus A')
        ->and($catalogus->is_active)->toBeFalse();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && str_starts_with($request->url(), CATALOGUSSEN_URL));
});

test('it does not duplicate catalogi that already exist', function () {
    Queue::fake();

    Catalogus::factory()->create([
        'url' => 'https://openzaak.example.com/catalogi/api/v1/catalogussen/aaa',
        'omschrijving' => 'Catalogus A',
        'is_active' => false,
    ]);

    fakeCatalogussen([
        ['url' => 'https://openzaak.example.com/catalogi/api/v1/catalogussen/aaa', 'naam' => 'Catalogus A'],
    ]);

    runSyncAllCatalogi();

    expect(Catalogus::count())->toBe(1);
});

test('it deactivates active catalogi that no longer exist in ZGW', function () {
    Queue::fake();

    $gone = Catalogus::factory()->create([
        'url' => 'https://openzaak.example.com/catalogi/api/v1/catalogussen/gone',
        'is_active' => true,
    ]);

    fakeCatalogussen([
        ['url' => 'https://openzaak.example.com/catalogi/api/v1/catalogussen/aaa', 'naam' => 'Catalogus A'],
    ]);

    runSyncAllCatalogi();

    expect($gone->fresh()->is_active)->toBeFalse();
});

test('it does not touch inactive catalogi that are gone from ZGW', function () {
    Queue::fake();

    $inactive = Catalogus::factory()->inactive()->create([
        'url' => 'https://openzaak.example.com/catalogi/api/v1/catalogussen/gone',
    ]);

    fakeCatalogussen([]);

    runSyncAllCatalogi();

    // Still exists and still inactive — no change
    expect($inactive->fresh()->is_active)->toBeFalse();
});

test('it dispatches SyncCatalogus for each active catalogus', function () {
    Queue::fake();

    $active = Catalogus::factory()->create([
        'url' => 'https://openzaak.example.com/catalogi/api/v1/catalogussen/active',
        'is_active' => true,
    ]);

    fakeCatalogussen([
        ['url' => 'https://openzaak.example.com/catalogi/api/v1/catalogussen/active', 'naam' => 'Active'],
    ]);

    runSyncAllCatalogi();

    Queue::assertPushed(SyncCatalogus::class, fn ($job) => true);
});

test('it reads catalogi from every page and keeps an active catalogus on a later page active', function () {
    Queue::fake();

    $onSecondPage = Catalogus::factory()->create([
        'url' => 'https://openzaak.example.com/catalogi/api/v1/catalogussen/bbb',
        'is_active' => true,
    ]);

    $page2Url = CATALOGUSSEN_URL.'?page=2';

    Http::fake(function (Request $request) use ($page2Url) {
        if ($request->url() === $page2Url) {
            return Http::response([
                'count' => 2,
                'next' => null,
                'previous' => CATALOGUSSEN_URL,
                'results' => [['url' => 'https://openzaak.example.com/catalogi/api/v1/catalogussen/bbb', 'naam' => 'Catalogus B']],
            ]);
        }

        return Http::response([
            'count' => 2,
            'next' => $page2Url,
            'previous' => null,
            'results' => [['url' => 'https://openzaak.example.com/catalogi/api/v1/catalogussen/aaa', 'naam' => 'Catalogus A']],
        ]);
    });

    runSyncAllCatalogi();

    expect(Catalogus::count())->toBe(2)
        ->and($onSecondPage->fresh()->is_active)->toBeTrue();

    // The list is fetched once, although the job walks it twice.
    Http::assertSentCount(2);
});
