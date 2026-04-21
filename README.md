# Corsa ZGW

## About

Corsa ZGW is an integration service that bridges [OpenNotificaties](https://open-notificaties.readthedocs.io/) (a Dutch government notification system) and Corsa ZaakDMS (a document management system). It receives case (zaak) notifications over an API, batches them per case, and processes them in the correct order to keep the DMS up to date.

The application handles four types of notifications:

- **Zaak aangemaakt** (case created): always processed first to ensure the case exists in Corsa before any related data is added.
- **Zaakstatus** (status update): updates the current status of an existing case in Corsa.
- **Zaakinformatieobject** (document attached): downloads and uploads a document from OpenZaak to Corsa.
- **Resultaat aangemaakt** (result registered): always processed last, closes the case in Corsa with the final outcome and end date.

A cache-based timer groups all notifications for a single case that arrive within a configurable window. When the timer expires, notifications are processed as an ordered job chain via Laravel's `Bus::chain()`, guaranteeing that `create:zaak` always runs first.

For a detailed description of the batching logic and processing order, see [docs/batching_system.md](docs/batching_system.md) and [docs/notificaties.md](docs/notificaties.md).

## Architecture

```
POST /api/v1/notifications (Sanctum)
  └─ CheckIncomingNotification (job)
       └─ BatchingService: getOrCreateBatch() + addNotificationToBatch()
            └─ Cache timer (NOTIFICATION_BATCH_TIMEOUT seconds, resets on each new notification)

[Scheduler: every minute]
  └─ TriggerBatchProcessing (job)
       └─ ProcessBatch (job, per expired batch)
            └─ Bus::chain([
                 ProcessNotification(create:zaak),       ← always first
                 ProcessNotification(create:status),
                 ProcessNotification(create:zaakinformatieobject),
                 ProcessNotification(create:resultaat),  ← always last
               ])
                 └─ CorsaZaakdmsService
                      ├─ creeerZaak()
                      ├─ actualiseerZaakstatus()
                      ├─ voegZaakdocumentToe()
                      └─ updateZaak()
```

## API

The application exposes a single webhook endpoint. OpenNotificaties must be configured to POST to it.

```
POST /api/v1/notifications
Authorization: Bearer {sanctum-token}
Content-Type: application/json
```

Three notification types on the `zaken` kanaal are accepted. See [docs/API-Call-mapping.md](docs/API-Call-mapping.md) for full payload examples.

| `resource`              | Effect in Corsa                          | Order |
|-------------------------|------------------------------------------|-------|
| `zaak`                  | Creates a new zaak                       | First |
| `status`                | Updates the zaak status                  | Middle |
| `zaakinformatieobject`  | Uploads a document to the zaak           | Middle |
| `resultaat`             | Closes the zaak with a final outcome     | Last |

## Technical Stack

- **Framework**: Laravel 12.x
- **PHP Version**: PHP 8.4
- **Frontend**: Tailwind CSS v4, Vite
- **Admin Panel**: Filament v4
- **Database**: PostgreSQL
- **Queue System**: Redis (via Laravel Horizon)
- **Docker Support**: Laravel Sail

## Configuration

The following environment variables must be configured:

### OpenZaak (ZGW API)

| Variable | Description |
|---|---|
| `OPENZAAK_URL` | Base URL of the OpenZaak instance |
| `OPENZAAK_CLIENT_ID` | OAuth client ID for OpenZaak |
| `OPENZAAK_CLIENT_SECRET` | OAuth client secret for OpenZaak |

### Corsa ZaakDMS

| Variable | Description |
|---|---|
| `ZAAKDMS_URL` | SOAP endpoint URL of the Corsa ZaakDMS service |
| `ZAAKDMS_SENDER_APPLICATION` | Sender application identifier |
| `ZAAKDMS_SENDER_ADMINISTRATIVE` | Sender administrative unit |
| `ZAAKDMS_SENDER_ORGANISATION` | Sender organisation name |
| `ZAAKDMS_SENDER_USER` | Sender username (default: `Systeemgebruiker`) |
| `FIXED_CORSA_ZAAKTYPE_CODE` | Fixed zaaktype code used when creating zaken in Corsa (default: `226`) |

### Batching

| Variable | Default | Description |
|---|---|---|
| `NOTIFICATION_BATCH_TIMEOUT` | `60` | Seconds to wait before processing a batch |
| `NOTIFICATION_BATCH_MAX_SIZE` | `100` | Maximum notifications in a single batch |
| `NOTIFICATION_USE_QUEUE` | `true` | Whether to process via queue |
| `NOTIFICATION_QUEUE` | `default` | Queue name for batch processing jobs |

## Installation

### Requirements (Local or Docker)

- PHP 8.4+, Composer, Node.js/NPM, PostgreSQL, Redis (for local setup)
- Or: [Laravel Sail](https://laravel.com/docs/sail) with Docker for easy environment setup

### Quick Start (Docker using Sail)

1. Clone the repository:
   ```bash
   git clone [repository-url]
   cd [repository-url]
   ```

2. Copy the environment file and set your settings:
   ```bash
   cp .env.example .env
   ```

3. Start the application using Sail:
   ```bash
   ./vendor/bin/sail up -d
   ```

4. Generate an app key, run migrations and install dependencies:
   ```bash
   ./vendor/bin/sail artisan key:generate
   ./vendor/bin/sail artisan migrate
   ./vendor/bin/sail npm install && ./vendor/bin/sail npm run dev
   ```

### Or run locally without Sail

If you prefer a local setup:
```bash
composer install
npm install && npm run dev
php artisan migrate
php artisan serve
```

### Dependency updates
```
./vendor/bin/sail composer update
```

## Development Tools

### Laravel Pint

Code style is enforced using Laravel Pint:
```bash
./vendor/bin/pint
```

### Pest

Pest is used for writing and running tests:
```bash
./vendor/bin/pest
```

### PHPStan

We use PHPStan for static code analysis to catch bugs early. It will be fully integrated soon. To run:
```bash
./vendor/bin/phpstan analyse --memory-limit=2G
```

### Rector

Rector helps upgrade and refactor the codebase for Laravel 12 compatibility:
```bash
./vendor/bin/rector process
```

### Pre-commit Hook

A pre-commit hook ensures code quality by running Pint, PHPStan, Rector (in dry-run mode), and Pest before commits.  
To activate the hooks:

```bash
git config core.hooksPath .githooks
```

This setup will automatically run the following on commit:

- Pint (code style)
- PHPStan (static analysis)
- Rector (dry-run, no changes made)
- Pest (tests)

You can find the hook script inside the `.githooks/` directory.

## Deploying on server

## Contributing

1. Create a feature branch from the `main` branch
2. Make your changes
3. Run the pre-commit checks
4. Open a pull request

## License
