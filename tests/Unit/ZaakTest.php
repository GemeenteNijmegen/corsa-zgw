<?php

use App\ValueObjects\ZGW\Rol;
use App\ValueObjects\ZGW\Zaak;

function unitZaakData(array $expand = []): array
{
    return [
        'url' => 'https://openzaak.example.com/zaken/api/v1/zaken/00000000-0000-0000-0000-000000000001',
        'uuid' => '00000000-0000-0000-0000-000000000001',
        'identificatie' => 'ZAAK-2026-0001',
        'bronorganisatie' => '000000000',
        'omschrijving' => 'Testzaak',
        'zaaktype' => 'https://openzaak.example.com/catalogi/api/v1/zaaktypen/00000000-0000-0000-0000-000000000101',
        'registratiedatum' => '2026-01-15',
        'startdatum' => '2026-01-14',
        'einddatum' => null,
        'einddatumGepland' => null,
        'uiterlijkeEinddatumAfdoening' => null,
        'zaakgeometrie' => null,
        'toelichting' => 'Collected in otherParams',
        '_expand' => $expand,
    ];
}

function unitRolData(string $omschrijvingGeneriek, string $uuid): array
{
    return [
        'url' => 'https://openzaak.example.com/zaken/api/v1/rollen/'.$uuid,
        'uuid' => $uuid,
        'betrokkeneType' => 'natuurlijk_persoon',
        'roltype' => 'https://openzaak.example.com/catalogi/api/v1/roltypen/00000000-0000-0000-0000-000000000801',
        'omschrijving' => ucfirst($omschrijvingGeneriek),
        'omschrijvingGeneriek' => $omschrijvingGeneriek,
        'contactpersoonRol' => ['emailadres' => '', 'functie' => '', 'telefoonnummer' => '', 'naam' => ''],
        'betrokkeneIdentificatie' => ['inpBsn' => '000000000', 'geslachtsnaam' => 'Testpersoon'],
        'registratiedatum' => '2026-01-15T10:00:00Z',
    ];
}

test('Zaak picks the initiator from the expanded rollen', function () {
    $zaak = new Zaak(...unitZaakData([
        'rollen' => [
            unitRolData('belanghebbende', '00000000-0000-0000-0000-000000000702'),
            unitRolData('initiator', '00000000-0000-0000-0000-000000000701'),
        ],
    ]));

    expect($zaak->initiator)->toBeInstanceOf(Rol::class)
        ->and($zaak->initiator->uuid)->toBe('00000000-0000-0000-0000-000000000701')
        ->and($zaak->initiator->betrokkeneIdentificatie['geslachtsnaam'])->toBe('Testpersoon')
        ->and($zaak->otherParams)->toBe(['toelichting' => 'Collected in otherParams']);
});

test('Zaak has no initiator and no status when nothing is expanded', function () {
    $zaak = new Zaak(...unitZaakData());

    expect($zaak->initiator)->toBeNull()
        ->and($zaak->status)->toBeNull()
        ->and($zaak->status_name)->toBeNull()
        ->and($zaak->zaakAddresses)->toBe([])
        ->and($zaak->startdatum_datetime->format('Y-m-d'))->toBe('2026-01-14');
});

test('Zaak reads the status name from the expanded statustype', function () {
    $status = [
        'url' => 'https://openzaak.example.com/zaken/api/v1/statussen/00000000-0000-0000-0000-000000000201',
        '_expand' => ['statustype' => ['omschrijving' => 'In behandeling']],
    ];

    $zaak = new Zaak(...unitZaakData(['status' => $status]));

    expect($zaak->status)->toBe($status)
        ->and($zaak->status_name)->toBe('In behandeling')
        ->and($zaak->toArray()['status_name'])->toBe('In behandeling');
});

test('Zaak only builds addresses from address objects with the event address relation', function () {
    $zaak = new Zaak(...unitZaakData([
        'zaakobjecten' => [
            [
                'objectType' => 'adres',
                'relatieomschrijving' => 'Adres van het evenement',
                'objectIdentificatie' => [
                    'postcode' => '0000AA',
                    'huisnummer' => 1,
                    'huisletter' => 'A',
                    'huisnummertoevoeging' => '',
                    'wplWoonplaatsNaam' => 'Teststad',
                ],
            ],
            [
                'objectType' => 'adres',
                'relatieomschrijving' => 'Correspondentieadres',
                'objectIdentificatie' => ['postcode' => '9999ZZ', 'huisnummer' => 2, 'wplWoonplaatsNaam' => 'Teststad'],
            ],
            [
                'objectType' => 'overige',
                'relatieomschrijving' => 'Adres van het evenement',
                'objectIdentificatie' => ['postcode' => '1111BB'],
            ],
        ],
    ]));

    expect($zaak->zaakAddresses)->toBe(['0000AA 1 A  Teststad']);
});

test('Rol::toArray returns the expected keys and drops unknown fields', function () {
    $data = unitRolData('initiator', '00000000-0000-0000-0000-000000000701');

    $rol = new Rol(...$data);

    expect($rol->toArray())->toBe([
        'uuid' => $data['uuid'],
        'url' => $data['url'],
        'betrokkeneType' => 'natuurlijk_persoon',
        'roltype' => $data['roltype'],
        'omschrijving' => 'Initiator',
        'omschrijvingGeneriek' => 'initiator',
        'contactpersoonRol' => $data['contactpersoonRol'],
        'betrokkeneIdentificatie' => $data['betrokkeneIdentificatie'],
    ]);
});

test('known bug: Zaak cannot be constructed when the expanded rollen contain no initiator', function () {
    expect(fn () => new Zaak(...unitZaakData([
        'rollen' => [unitRolData('belanghebbende', '00000000-0000-0000-0000-000000000702')],
    ])))->toThrow(TypeError::class);
});
