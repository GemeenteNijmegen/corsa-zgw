<?php

/*
|--------------------------------------------------------------------------
| Fallback to the OPENZAAK_* variables
|--------------------------------------------------------------------------
|
| The ZGW_* variables below are the primary configuration. When a ZGW_*
| variable is not set (or empty), the value falls back to the OPENZAAK_*
| variable that was used before, so an existing environment keeps working
| without changes. OPENZAAK_URL is the root URL of a single-host Open Zaak
| instance; the API base URLs are derived from it in the same way as before
| ("{OPENZAAK_URL}/zaken/api/v1/" and so on).
|
*/

$openzaakUrl = rtrim((string) env('OPENZAAK_URL', ''), '/');

$baseUrl = static fn (string $variable, string $api): string => (string) (env($variable) ?: ($openzaakUrl === '' ? '' : $openzaakUrl.'/'.$api.'/api/v1/'));

return [

    'default' => env('ZGW_CONNECTION', 'main'),

    'connections' => [

        'main' => [

            /*
            | Full base URL per ZGW API, including the version path and a trailing slash.
            | The origins of these URLs are the hosts the client is allowed to send requests to.
            */
            'urls' => [
                'zaken' => $baseUrl('ZGW_ZAKEN_BASE_URL', 'zaken'),
                'catalogi' => $baseUrl('ZGW_CATALOGI_BASE_URL', 'catalogi'),
                'documenten' => $baseUrl('ZGW_DOCUMENTEN_BASE_URL', 'documenten'),
            ],

            /*
            | The ZGW standard release of the provider: "1.5", "1.6" or "1.7".
            */
            'version' => env('ZGW_VERSION', '1.7'),

            /*
            | JWT credentials. The client secret is the HS256 signing key and must be at
            | least 32 bytes long; a shorter secret is rejected when the connection is built.
            */
            'client_id' => (string) (env('ZGW_CLIENT_ID') ?: env('OPENZAAK_CLIENT_ID', '')),
            'client_secret' => (string) (env('ZGW_CLIENT_SECRET') ?: env('OPENZAAK_CLIENT_SECRET', '')),
            'user_id' => (string) (env('ZGW_USER_ID') ?: 'application'),
            'user_representation' => (string) (env('ZGW_USER_REPRESENTATION') ?: 'Application background task'),

            /*
            | JWT lifetime in seconds ("exp" claim). Tokens are signed per request.
            */
            'jwt_expiry' => (int) env('ZGW_JWT_EXPIRY', 300),

            'secret_rules' => [
                'min_length' => (int) env('ZGW_SECRET_MIN_LENGTH', 32),
                'require_uppercase' => false,
                'require_lowercase' => false,
                'require_number' => false,
                'require_symbol' => false,
            ],

            'cache_store' => env('ZGW_CACHE_STORE'),

            'accept_crs' => env('ZGW_ACCEPT_CRS', 'EPSG:4326'),
            'content_crs' => env('ZGW_CONTENT_CRS', 'EPSG:4326'),

            /*
            | Extra origins the client may send requests to, on top of the base URLs above.
            | Only needed when the provider returns links on another host. Comma separated;
            | each entry is a full URL or a bare host (a bare host is treated as https).
            */
            'allowed_hosts' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('ZGW_ALLOWED_HOSTS', '')),
            ))),

            'connect_timeout' => (int) env('ZGW_CONNECT_TIMEOUT', 10),
            'timeout' => (int) env('ZGW_TIMEOUT', 30),

            'max_pages' => (int) env('ZGW_MAX_PAGES', 1000),

            'retry_times' => (int) env('ZGW_RETRY_TIMES', 0),
            'retry_sleep_ms' => (int) env('ZGW_RETRY_SLEEP_MS', 100),
            'retry_max_sleep_ms' => (int) env('ZGW_RETRY_MAX_SLEEP_MS', 5000),

        ],

    ],

];
