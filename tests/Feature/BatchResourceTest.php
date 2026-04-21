<?php

use App\Filament\Resources\Batches\Pages\ListBatches;
use App\Filament\Resources\Batches\Pages\ViewBatch;
use App\Filament\Resources\Batches\RelationManagers\NotificationsRelationManager;
use App\Models\Batch;
use App\Models\Notification;
use App\Models\User;
use Livewire\Livewire;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

// ─── List page ───────────────────────────────────────────────────────────────

test('it can render the batches list page', function () {
    Livewire::test(ListBatches::class)
        ->assertSuccessful();
});

test('it shows batches in the table', function () {
    $batches = Batch::factory()->count(3)->create();

    Livewire::test(ListBatches::class)
        ->assertCanSeeTableRecords($batches);
});

test('it can search batches by zaak_identificatie', function () {
    $matching = Batch::factory()->create(['zaak_identificatie' => 'ZAAK-2026-1111']);
    $other = Batch::factory()->create(['zaak_identificatie' => 'ZAAK-2026-9999']);

    Livewire::test(ListBatches::class)
        ->searchTable('ZAAK-2026-1111')
        ->assertCanSeeTableRecords([$matching])
        ->assertCanNotSeeTableRecords([$other]);
});

test('it can filter batches by status', function () {
    $pending = Batch::factory()->pending()->create();
    $processed = Batch::factory()->processed()->create();

    Livewire::test(ListBatches::class)
        ->filterTable('status', 'pending')
        ->assertCanSeeTableRecords([$pending])
        ->assertCanNotSeeTableRecords([$processed]);
});

// ─── View page ───────────────────────────────────────────────────────────────

test('it can render the view batch page', function () {
    $batch = Batch::factory()->create();

    Livewire::test(ViewBatch::class, ['record' => $batch->getRouteKey()])
        ->assertSuccessful();
});

// ─── Notifications relation manager ──────────────────────────────────────────

test('it shows notifications in the relation manager', function () {
    $batch = Batch::factory()->create(['zaak_identificatie' => 'ZAAK-2026-7777']);
    $notifications = Notification::factory()->count(2)->create([
        'batch_id' => $batch->id,
        'zaak_identificatie' => $batch->zaak_identificatie,
    ]);

    Livewire::test(NotificationsRelationManager::class, [
        'ownerRecord' => $batch,
        'pageClass' => ViewBatch::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords($notifications);
});

test('it shows create:zaak and create:resultaat notifications', function () {
    $batch = Batch::factory()->create(['zaak_identificatie' => 'ZAAK-2026-8888']);

    $zaakNotification = Notification::factory()->forZaak()->create([
        'batch_id' => $batch->id,
        'zaak_identificatie' => $batch->zaak_identificatie,
    ]);
    $resultaatNotification = Notification::factory()->forResultaat()->create([
        'batch_id' => $batch->id,
        'zaak_identificatie' => $batch->zaak_identificatie,
    ]);

    Livewire::test(NotificationsRelationManager::class, [
        'ownerRecord' => $batch,
        'pageClass' => ViewBatch::class,
    ])
        ->assertCanSeeTableRecords([$zaakNotification, $resultaatNotification]);
});

test('it renders the relation manager for a processed batch with processed_at', function () {
    $batch = Batch::factory()->processed()->create();
    Notification::factory()->processed()->create([
        'batch_id' => $batch->id,
        'zaak_identificatie' => $batch->zaak_identificatie,
    ]);

    Livewire::test(NotificationsRelationManager::class, [
        'ownerRecord' => $batch,
        'pageClass' => ViewBatch::class,
    ])
        ->assertSuccessful();
});
