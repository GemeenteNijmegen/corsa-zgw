<?php

use App\Jobs\CheckIncomingNotification;
use App\Models\Batch;
use App\Models\Notification;
use App\Services\BatchingService;
use App\ValueObjects\OpenNotification;
use Illuminate\Support\Facades\Log;
use Woweb\Openzaak\Openzaak;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

test('it skips processing when kanaal is not zaken', function () {
    $openNotification = new OpenNotification(
        actie: 'create',
        kanaal: 'documenten',
        resource: 'document',
        hoofdObject: 'https://example.com/zaak/123',
        resourceUrl: 'https://example.com/document/456',
        aanmaakdatum: '2024-01-01T10:00:00Z',
    );

    $openzaakSpy = Mockery::spy(Openzaak::class);
    $batchingServiceSpy = Mockery::spy(BatchingService::class);

    $job = new CheckIncomingNotification($openNotification);
    $job->handle($openzaakSpy, $batchingServiceSpy);

    $openzaakSpy->shouldNotHaveReceived('get');
    $batchingServiceSpy->shouldNotHaveReceived('getOrCreateBatch');
    
    expect(Notification::count())->toBe(0);
});

test('it processes when kanaal is zaken', function () {
    Log::spy();

    $openNotification = new OpenNotification(
        actie: 'create',
        kanaal: 'zaken',
        resource: 'zaak',
        hoofdObject: 'https://example.com/zaak/123',
        resourceUrl: 'https://example.com/zaak/123',
        aanmaakdatum: '2024-01-01T10:00:00Z',
    );

    $zaakData = [
        'uuid' => '550e8400-e29b-41d4-a716-446655440000',
        'url' => 'https://example.com/zaak/123',
        'identificatie' => 'ZAAK-2024-001',
        'zaaktype' => 'https://example.com/zaaktypen/123',
        'omschrijving' => 'Test zaak',
        'startdatum' => '2024-01-15',
        'registratiedatum' => '2024-01-15T10:30:00Z',
        'einddatum' => null,
        'einddatumGepland' => null,
        'uiterlijkeEinddatumAfdoening' => null,
        'bronorganisatie' => null,
        'zaakgeometrie' => null,
    ];

    $openzaakMock = Mockery::mock(Openzaak::class);
    $openzaakMock->shouldReceive('get')
        ->once()
        ->with($openNotification->hoofdObject)
        ->andReturn(collect($zaakData));

    // Don't set ID, let Laravel generate it
    $fakeBatch = Batch::create([
        'zaak_identificatie' => 'ZAAK-2024-001',
    ]);

    $batchingServiceMock = Mockery::mock(BatchingService::class);
    $batchingServiceMock->shouldReceive('getOrCreateBatch')
        ->once()
        ->with('ZAAK-2024-001')
        ->andReturn($fakeBatch);

    $batchingServiceMock->shouldReceive('addNotificationToBatch')
        ->once()
        ->withArgs(function ($notification, $batch) use ($fakeBatch) {
            return $notification instanceof Notification 
                && $batch->id === $fakeBatch->id;
        });

    $job = new CheckIncomingNotification($openNotification);
    $job->handle($openzaakMock, $batchingServiceMock);

    expect(Notification::count())->toBe(1);
    
    $notification = Notification::first();
    expect($notification->zaak_identificatie)->toBe('ZAAK-2024-001')
        ->and($notification->processed)->toBeFalse();
});
