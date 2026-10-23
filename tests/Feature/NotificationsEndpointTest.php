<?php

use App\Jobs\Notifications\IngestNotification;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

const NOTIFICATIONS_ENDPOINT = '/api/v1/notifications';

function validNotificationPayload(array $overrides = []): array
{
    return array_merge([
        'actie' => 'create',
        'kanaal' => 'zaken',
        'resource' => 'zaak',
        'hoofdObject' => 'https://openzaak.example.com/zaken/api/v1/zaken/00000000-0000-0000-0000-000000000001',
        'resourceUrl' => 'https://openzaak.example.com/zaken/api/v1/zaken/00000000-0000-0000-0000-000000000001',
        'aanmaakdatum' => '2026-01-15T10:00:00Z',
    ], $overrides);
}

beforeEach(function () {
    Queue::fake();
});

test('it rejects a request without a token with 401', function () {
    $this->postJson(NOTIFICATIONS_ENDPOINT, validNotificationPayload())
        ->assertUnauthorized();

    Queue::assertNothingPushed();
});

test('it accepts a valid notification and dispatches IngestNotification with exactly the validated fields', function () {
    Sanctum::actingAs(User::factory()->create());

    $payload = validNotificationPayload();

    $this->postJson(NOTIFICATIONS_ENDPOINT, array_merge($payload, ['extra' => 'ignored']))
        ->assertOk();

    Queue::assertPushed(IngestNotification::class, 1);
    Queue::assertPushed(IngestNotification::class, function (IngestNotification $job) use ($payload) {
        $notification = (fn () => $this->opennotification)->call($job);

        return $notification->toArray() === array_merge($payload, ['kenmerken' => []]);
    });
});

test('it passes the kenmerken of the notification on to IngestNotification', function () {
    Sanctum::actingAs(User::factory()->create());

    $kenmerken = [
        'bronorganisatie' => '000000000',
        'zaaktype' => 'https://openzaak.example.com/catalogi/api/v1/zaaktypen/00000000-0000-0000-0000-000000000101',
        'vertrouwelijkheidaanduiding' => 'openbaar',
    ];

    $this->postJson(NOTIFICATIONS_ENDPOINT, validNotificationPayload(['kenmerken' => $kenmerken]))
        ->assertOk();

    Queue::assertPushed(IngestNotification::class, function (IngestNotification $job) use ($kenmerken) {
        $notification = (fn () => $this->opennotification)->call($job);

        return $notification->kenmerken === $kenmerken;
    });
});

test('it accepts kenmerken that are null', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->postJson(NOTIFICATIONS_ENDPOINT, validNotificationPayload(['kenmerken' => null]))
        ->assertOk();

    Queue::assertPushed(IngestNotification::class, function (IngestNotification $job) {
        $notification = (fn () => $this->opennotification)->call($job);

        return $notification->kenmerken === [];
    });
});

test('it rejects values beyond the limits of the Notificaties API message', function (array $overrides, string $field) {
    Sanctum::actingAs(User::factory()->create());

    $this->postJson(NOTIFICATIONS_ENDPOINT, validNotificationPayload($overrides))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);

    Queue::assertNothingPushed();
})->with([
    'kanaal of 51 characters' => [['kanaal' => str_repeat('k', 51)], 'kanaal'],
    'resource of 101 characters' => [['resource' => str_repeat('r', 101)], 'resource'],
    'kenmerk of 1001 characters' => [['kenmerken' => ['zaaktype' => str_repeat('z', 1001)]], 'kenmerken.zaaktype'],
    'nested kenmerk value' => [['kenmerken' => ['zaaktype' => ['nested' => 'value']]], 'kenmerken.zaaktype'],
]);

test('it accepts values at the limits of the Notificaties API message', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->postJson(NOTIFICATIONS_ENDPOINT, validNotificationPayload([
        'kanaal' => str_repeat('k', 50),
        'resource' => str_repeat('r', 100),
        'kenmerken' => ['zaaktype' => str_repeat('z', 1000), 'leeg' => ''],
    ]))->assertOk();

    Queue::assertPushed(IngestNotification::class, 1);
});

test('it rejects kenmerken that are not an object', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->postJson(NOTIFICATIONS_ENDPOINT, validNotificationPayload(['kenmerken' => 'zaaktype']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['kenmerken']);

    Queue::assertNothingPushed();
});

test('it accepts a token created through the API token flow', function () {
    $user = User::factory()->create();
    $plainTextToken = $user->createToken('api-client')->plainTextToken;

    $this->withToken($plainTextToken)
        ->postJson(NOTIFICATIONS_ENDPOINT, validNotificationPayload())
        ->assertOk();

    Queue::assertPushed(IngestNotification::class, 1);
});

test('it rejects a payload with a missing required field', function (string $field) {
    Sanctum::actingAs(User::factory()->create());

    $payload = validNotificationPayload();
    unset($payload[$field]);

    $this->postJson(NOTIFICATIONS_ENDPOINT, $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);

    Queue::assertNothingPushed();
})->with(['actie', 'kanaal', 'resource', 'hoofdObject', 'resourceUrl', 'aanmaakdatum']);

test('it rejects an actie that the ZGW APIs do not publish', function (string $actie) {
    Sanctum::actingAs(User::factory()->create());

    $this->postJson(NOTIFICATIONS_ENDPOINT, validNotificationPayload(['actie' => $actie]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['actie']);

    Queue::assertNothingPushed();
})->with(['delete', 'remove']);

test('it accepts a kanaal and resource that are not processed, so they can be stored as ignored', function (string $kanaal, string $resource) {
    Sanctum::actingAs(User::factory()->create());

    $this->postJson(NOTIFICATIONS_ENDPOINT, validNotificationPayload(['kanaal' => $kanaal, 'resource' => $resource]))
        ->assertOk();

    Queue::assertPushed(IngestNotification::class, 1);
})->with([
    'rol on zaken' => ['zaken', 'rol'],
    'document on documenten' => ['documenten', 'enkelvoudiginformatieobject'],
    'besluit on besluiten' => ['besluiten', 'besluit'],
]);

test('it rejects a value that is not a URL', function (string $field) {
    Sanctum::actingAs(User::factory()->create());

    $this->postJson(NOTIFICATIONS_ENDPOINT, validNotificationPayload([$field => 'not-a-url']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);

    Queue::assertNothingPushed();
})->with(['hoofdObject', 'resourceUrl']);

test('it rejects an aanmaakdatum that is not a date', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->postJson(NOTIFICATIONS_ENDPOINT, validNotificationPayload(['aanmaakdatum' => 'yesterday-ish']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['aanmaakdatum']);

    Queue::assertNothingPushed();
});

test('it accepts every action the ZGW APIs publish', function (string $actie) {
    Sanctum::actingAs(User::factory()->create());

    $this->postJson(NOTIFICATIONS_ENDPOINT, validNotificationPayload(['actie' => $actie]))
        ->assertOk();

    Queue::assertPushed(IngestNotification::class, 1);
})->with(['create', 'update', 'partial_update', 'destroy']);

test('it rejects a URL that is not on a configured ZGW host', function (string $field, string $url) {
    Sanctum::actingAs(User::factory()->create());

    $this->postJson(NOTIFICATIONS_ENDPOINT, validNotificationPayload([$field => $url]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);

    Queue::assertNothingPushed();
})->with([
    'hoofdObject on another host' => ['hoofdObject', 'https://elsewhere.example.com/zaken/api/v1/zaken/00000000-0000-0000-0000-000000000001'],
    'resourceUrl on another host' => ['resourceUrl', 'https://elsewhere.example.com/zaken/api/v1/zaken/00000000-0000-0000-0000-000000000001'],
    'hoofdObject over plain http' => ['hoofdObject', 'http://openzaak.example.com/zaken/api/v1/zaken/00000000-0000-0000-0000-000000000001'],
    'resourceUrl on another port' => ['resourceUrl', 'https://openzaak.example.com:8443/zaken/api/v1/zaken/00000000-0000-0000-0000-000000000001'],
]);

test('it accepts a URL on a host from the extra allowed hosts', function () {
    Sanctum::actingAs(User::factory()->create());
    config(['zgw.connections.main.allowed_hosts' => ['documents.example.com']]);

    $this->postJson(NOTIFICATIONS_ENDPOINT, validNotificationPayload([
        'resourceUrl' => 'https://documents.example.com/documenten/api/v1/enkelvoudiginformatieobjecten/00000000-0000-0000-0000-000000000001',
    ]))->assertOk();

    Queue::assertPushed(IngestNotification::class, 1);
});
