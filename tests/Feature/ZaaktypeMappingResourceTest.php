<?php

use App\Filament\Resources\ZaaktypeMappings\Pages\EditZaaktypeMapping;
use App\Filament\Resources\ZaaktypeMappings\Pages\ListZaaktypeMappings;
use App\Jobs\SyncAllCatalogiJob;
use App\Models\Catalogus;
use App\Models\User;
use App\Models\ZaaktypeMapping;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

// --- List ---

test('it can render the zaaktype mappings list page', function () {
    Livewire::test(ListZaaktypeMappings::class)
        ->assertSuccessful();
});

test('it shows zaaktype mappings in the table', function () {
    $mappings = ZaaktypeMapping::factory()->count(3)->create();

    Livewire::test(ListZaaktypeMappings::class)
        ->assertCanSeeTableRecords($mappings);
});

test('it can dispatch SyncAllCatalogiJob from the table toolbar action', function () {
    Queue::fake();

    Livewire::test(ListZaaktypeMappings::class)
        ->callTableAction('syncAll');

    Queue::assertPushed(SyncAllCatalogiJob::class);
});

test('it can filter by active status', function () {
    $active = ZaaktypeMapping::factory()->active()->create();
    $inactive = ZaaktypeMapping::factory()->create(['is_active' => false]);

    Livewire::test(ListZaaktypeMappings::class)
        ->filterTable('is_active', true)
        ->assertCanSeeTableRecords([$active])
        ->assertCanNotSeeTableRecords([$inactive]);
});

test('it can filter by catalogus', function () {
    $catalogusA = Catalogus::factory()->create();
    $catalogusB = Catalogus::factory()->create();

    $mappingA = ZaaktypeMapping::factory()->create(['catalogus_id' => $catalogusA->id]);
    $mappingB = ZaaktypeMapping::factory()->create(['catalogus_id' => $catalogusB->id]);

    Livewire::test(ListZaaktypeMappings::class)
        ->filterTable('catalogus', $catalogusA->id)
        ->assertCanSeeTableRecords([$mappingA])
        ->assertCanNotSeeTableRecords([$mappingB]);
});

// --- Edit ---

test('it can render the edit zaaktype mapping page', function () {
    $mapping = ZaaktypeMapping::factory()->create();

    Livewire::test(EditZaaktypeMapping::class, ['record' => $mapping->getRouteKey()])
        ->assertSuccessful();
});

test('it populates the edit form with existing data', function () {
    $mapping = ZaaktypeMapping::factory()->withCode('CODE_01')->create(['is_active' => true]);

    Livewire::test(EditZaaktypeMapping::class, ['record' => $mapping->getRouteKey()])
        ->assertFormSet([
            'corsa_zaaktype_code' => 'CODE_01',
            'is_active' => true,
        ]);
});

test('it can update corsa_zaaktype_code and is_active', function () {
    $mapping = ZaaktypeMapping::factory()->create([
        'corsa_zaaktype_code' => null,
        'is_active' => false,
    ]);

    Livewire::test(EditZaaktypeMapping::class, ['record' => $mapping->getRouteKey()])
        ->fillForm([
            'corsa_zaaktype_code' => 'MIJN_CODE',
            'is_active' => true,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($mapping->fresh()->corsa_zaaktype_code)->toBe('MIJN_CODE')
        ->and($mapping->fresh()->is_active)->toBeTrue();
});

test('it validates corsa_zaaktype_code max length', function () {
    $mapping = ZaaktypeMapping::factory()->create();

    Livewire::test(EditZaaktypeMapping::class, ['record' => $mapping->getRouteKey()])
        ->fillForm(['corsa_zaaktype_code' => str_repeat('A', 21)])
        ->call('save')
        ->assertHasFormErrors(['corsa_zaaktype_code' => 'max']);
});

test('it validates corsa_zaaktype_code regex', function () {
    $mapping = ZaaktypeMapping::factory()->create();

    Livewire::test(EditZaaktypeMapping::class, ['record' => $mapping->getRouteKey()])
        ->fillForm(['corsa_zaaktype_code' => 'invalid code!'])
        ->call('save')
        ->assertHasFormErrors(['corsa_zaaktype_code' => 'regex']);
});

test('it accepts valid corsa_zaaktype_code formats', function (string $code) {
    $mapping = ZaaktypeMapping::factory()->create();

    Livewire::test(EditZaaktypeMapping::class, ['record' => $mapping->getRouteKey()])
        ->fillForm(['corsa_zaaktype_code' => $code])
        ->call('save')
        ->assertHasNoFormErrors();
})->with([
    'letters only' => 'ABCDE',
    'digits only' => '12345',
    'mixed with underscore' => 'CODE_01',
    'lowercase' => 'code_abc',
]);

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

// --- List ---
