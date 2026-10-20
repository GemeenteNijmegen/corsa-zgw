<?php

use App\Services\Zgw\ZgwReader;

/**
 * Load config/zgw.php with the given environment variables set (a null value unsets the variable)
 * and restore the previous environment afterwards.
 */
function loadZgwConfigWith(array $variables): array
{
    $previous = [];

    foreach ($variables as $name => $value) {
        $previous[$name] = [$_SERVER[$name] ?? null, $_ENV[$name] ?? null, getenv($name)];
        setZgwTestEnv($name, $value);
    }

    try {
        return require config_path('zgw.php');
    } finally {
        foreach ($previous as $name => [$server, $env, $putenv]) {
            setZgwTestEnv($name, $server ?? $env ?? ($putenv === false ? null : $putenv));
        }
    }
}

function setZgwTestEnv(string $name, ?string $value): void
{
    if ($value === null) {
        unset($_SERVER[$name], $_ENV[$name]);
        putenv($name);

        return;
    }

    $_SERVER[$name] = $value;
    $_ENV[$name] = $value;
    putenv($name.'='.$value);
}

test('it derives the ZGW connection from the OPENZAAK variables', function () {
    $connection = loadZgwConfigWith([
        'OPENZAAK_URL' => 'https://openzaak.example.com/',
        'OPENZAAK_CLIENT_ID' => 'legacy-client',
        'OPENZAAK_CLIENT_SECRET' => 'legacy-secret-of-at-least-32-bytes-long',
        'ZGW_ZAKEN_BASE_URL' => null,
        'ZGW_CATALOGI_BASE_URL' => null,
        'ZGW_DOCUMENTEN_BASE_URL' => null,
        'ZGW_CLIENT_ID' => null,
        'ZGW_CLIENT_SECRET' => null,
        'ZGW_USER_ID' => null,
        'ZGW_USER_REPRESENTATION' => null,
        'ZGW_ALLOWED_HOSTS' => null,
    ])['connections']['main'];

    expect($connection['urls'])->toBe([
        'zaken' => 'https://openzaak.example.com/zaken/api/v1/',
        'catalogi' => 'https://openzaak.example.com/catalogi/api/v1/',
        'documenten' => 'https://openzaak.example.com/documenten/api/v1/',
    ])
        ->and($connection['client_id'])->toBe('legacy-client')
        ->and($connection['client_secret'])->toBe('legacy-secret-of-at-least-32-bytes-long')
        ->and($connection['user_id'])->toBe('application')
        ->and($connection['user_representation'])->toBe('Application background task')
        ->and($connection['allowed_hosts'])->toBe([]);
});

test('the ZGW variables take precedence over the OPENZAAK variables', function () {
    $connection = loadZgwConfigWith([
        'OPENZAAK_URL' => 'https://openzaak.example.com/',
        'OPENZAAK_CLIENT_ID' => 'legacy-client',
        'OPENZAAK_CLIENT_SECRET' => 'legacy-secret-of-at-least-32-bytes-long',
        'ZGW_ZAKEN_BASE_URL' => 'https://zaken.example.com/api/v1/',
        'ZGW_CATALOGI_BASE_URL' => 'https://catalogi.example.com/api/v1/',
        'ZGW_DOCUMENTEN_BASE_URL' => 'https://documenten.example.com/api/v1/',
        'ZGW_CLIENT_ID' => 'new-client',
        'ZGW_CLIENT_SECRET' => 'new-secret-of-at-least-32-bytes-long-too',
        'ZGW_USER_ID' => 'integration-user',
        'ZGW_USER_REPRESENTATION' => 'Integration user',
        'ZGW_ALLOWED_HOSTS' => 'files.example.com, https://downloads.example.com',
    ])['connections']['main'];

    expect($connection['urls'])->toBe([
        'zaken' => 'https://zaken.example.com/api/v1/',
        'catalogi' => 'https://catalogi.example.com/api/v1/',
        'documenten' => 'https://documenten.example.com/api/v1/',
    ])
        ->and($connection['client_id'])->toBe('new-client')
        ->and($connection['client_secret'])->toBe('new-secret-of-at-least-32-bytes-long-too')
        ->and($connection['user_id'])->toBe('integration-user')
        ->and($connection['user_representation'])->toBe('Integration user')
        ->and($connection['allowed_hosts'])->toBe(['files.example.com', 'https://downloads.example.com']);
});

test('the application boots with only the empty OPENZAAK variables from .env.example', function () {
    $config = loadZgwConfigWith([
        'OPENZAAK_URL' => '',
        'OPENZAAK_CLIENT_ID' => '',
        'OPENZAAK_CLIENT_SECRET' => '',
        'ZGW_ZAKEN_BASE_URL' => null,
        'ZGW_CATALOGI_BASE_URL' => null,
        'ZGW_DOCUMENTEN_BASE_URL' => null,
        'ZGW_CLIENT_ID' => null,
        'ZGW_CLIENT_SECRET' => null,
    ]);

    config(['zgw' => $config]);

    expect($config['connections']['main']['urls'])->toBe(['zaken' => '', 'catalogi' => '', 'documenten' => ''])
        ->and(app(ZgwReader::class))->toBeInstanceOf(ZgwReader::class);

    $this->get('/up')->assertOk();
});
