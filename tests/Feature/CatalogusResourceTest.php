<?php

use App\Filament\Resources\Catalogi\Pages\CreateCatalogus;
use App\Filament\Resources\Catalogi\Pages\EditCatalogus;
use App\Filament\Resources\Catalogi\Pages\ListCatalogi;
use App\Jobs\Sync\SyncAllCatalogi;
use App\Jobs\Sync\SyncCatalogus;
use App\Models\Catalogus;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

// --- List ---

test('it can render the catalogi list page', function () {
    Livewire::test(ListCatalogi::class)
        ->assertSuccessful();
});

test('it shows catalogi in the table', function () {
    $catalogi = Catalogus::factory()->count(3)->create();

    Livewire::test(ListCatalogi::class)
        ->assertCanSeeTableRecords($catalogi);
});

test('it can dispatch SyncAllCatalogi from the list header action', function () {
    Queue::fake();

    Livewire::test(ListCatalogi::class)
        ->callAction('syncAll');

    Queue::assertPushed(SyncAllCatalogi::class);
});

test('it can dispatch SyncCatalogus from the table row action', function () {
    Queue::fake();

    $catalogus = Catalogus::factory()->create();

    Livewire::test(ListCatalogi::class)
        ->callTableAction('sync', $catalogus);

    Queue::assertPushed(SyncCatalogus::class);
});

// --- Create ---

test('it can render the create catalogus page', function () {
    Livewire::test(CreateCatalogus::class)
        ->assertSuccessful();
});

test('it can create a catalogus', function () {
    Livewire::test(CreateCatalogus::class)
        ->fillForm([
            'url' => 'https://openzaak.example.com/catalogi/api/v1/catalogussen/new-uuid',
            'omschrijving' => 'Nieuwe catalogus',
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Catalogus::where('url', 'https://openzaak.example.com/catalogi/api/v1/catalogussen/new-uuid')->exists())->toBeTrue();
});

test('it validates required fields on create', function () {
    Livewire::test(CreateCatalogus::class)
        ->fillForm(['url' => ''])
        ->call('create')
        ->assertHasFormErrors(['url' => 'required']);
});

test('it validates url format on create', function () {
    Livewire::test(CreateCatalogus::class)
        ->fillForm(['url' => 'not-a-url'])
        ->call('create')
        ->assertHasFormErrors(['url' => 'url']);
});

// --- Edit ---

test('it can render the edit catalogus page', function () {
    $catalogus = Catalogus::factory()->create();

    Livewire::test(EditCatalogus::class, ['record' => $catalogus->getRouteKey()])
        ->assertSuccessful();
});

test('it populates the edit form with existing data', function () {
    $catalogus = Catalogus::factory()->create([
        'url' => 'https://openzaak.example.com/catalogi/api/v1/catalogussen/existing',
        'omschrijving' => 'Bestaande catalogus',
        'is_active' => true,
    ]);

    Livewire::test(EditCatalogus::class, ['record' => $catalogus->getRouteKey()])
        ->assertFormSet([
            'url' => 'https://openzaak.example.com/catalogi/api/v1/catalogussen/existing',
            'omschrijving' => 'Bestaande catalogus',
            'is_active' => true,
        ]);
});

test('it can update a catalogus', function () {
    $catalogus = Catalogus::factory()->create(['omschrijving' => 'Oud', 'is_active' => true]);

    Livewire::test(EditCatalogus::class, ['record' => $catalogus->getRouteKey()])
        ->fillForm(['omschrijving' => 'Nieuw', 'is_active' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($catalogus->fresh()->omschrijving)->toBe('Nieuw')
        ->and($catalogus->fresh()->is_active)->toBeFalse();
});
