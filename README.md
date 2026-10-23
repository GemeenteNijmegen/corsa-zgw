# Corsa ZGW

## About

Corsa ZGW is an integration service that bridges [OpenNotificaties](https://open-notificaties.readthedocs.io/) (a Dutch government notification system) and Corsa ZaakDMS (a document management system). It receives case (zaak) notifications over an API, batches them per case, and processes them in the correct order to keep the DMS up to date.

The application handles five types of notifications:

- **Zaak aangemaakt** (case created): always processed first to ensure the case exists in Corsa before any related data is added.
- **Zaakstatus** (status update): updates the current status of an existing case in Corsa.
- **Zaakinformatieobject** (document attached): downloads and uploads a document from OpenZaak to Corsa.
- **Enkelvoudiginformatieobject** (document changed, on the `documenten` kanaal): sends the newest version of a document that is already in Corsa.
- **Resultaat aangemaakt** (result registered): always processed last, closes the case in Corsa with the final outcome and end date.

A cache-based timer groups all notifications for a single case that arrive within a configurable window. When the timer expires, notifications are processed as an ordered job chain via Laravel's `Bus::chain()`, guaranteeing that `create:zaak` always runs first.

Two exceptions do not wait for the batch: a document that is linked to a zaak while it is still being edited (status `in_bewerking`), and every change to a document. Both are processed directly, as long as the zaak (for a new link) or the document (for a change) is already in Corsa. See [Direct route](#direct-route).

For a detailed description of the batching logic and processing order, see [docs/batching_system.md](docs/batching_system.md) and [docs/notificaties.md](docs/notificaties.md).

## Architecture

```
POST /api/v1/notifications (Sanctum)
  └─ IngestNotification (job)
       ├─ BatchingService: getOrCreateBatch() + addNotificationToBatch()
       │    └─ Cache timer (NOTIFICATION_BATCH_TIMEOUT seconds, resets on each new notification)
       └─ ProcessDocumentDirectly (job, queue "direct"), for a document linked while in_bewerking
            │    ├─ zaak in Corsa: CorsaZaakdmsService::addDocumentToZaak() → voegZaakdocumentToe()
            │    └─ zaak not in Corsa yet: moved to the batch of the zaak
            └─ and for a change to a document (update, partial_update)
                 ├─ document in Corsa: CorsaZaakdmsService::updateDocument() → updateZaakDocument()
                 └─ document not in Corsa yet: checked again after 30 s, 2 min and 5 min, then moved to the batch

[Scheduler: every minute]
  └─ FlushExpiredBatches (job)
       └─ DispatchBatch (job, per expired batch)
            └─ Bus::chain([
                 HandleNotification(create:zaak),            ← always first
                 HandleNotification(create:status),
                 HandleNotification(create:zaakinformatieobject),
                 HandleNotification(update:enkelvoudiginformatieobject), ← after the links
                 HandleNotification(create:resultaat),        ← always last
               ])
                 └─ CorsaZaakdmsService
                      ├─ creeerZaak()
                      ├─ actualiseerZaakstatus()
                      ├─ voegZaakdocumentToe()
                      ├─ updateZaakDocument()
                      └─ updateZaak()
```

## API

The application exposes a single webhook endpoint. OpenNotificaties must be configured to POST to it.

```
POST /api/v1/notifications
Authorization: Bearer {sanctum-token}
Content-Type: application/json
```

Four notification types on the `zaken` kanaal are processed, all with `actie` `create`, plus changes to a document on the `documenten` kanaal. See [docs/API-Call-mapping.md](docs/API-Call-mapping.md) for full payload examples.

| Kanaal       | `actie`                     | `resource`                    | Effect in Corsa                          | Order in a batch |
|--------------|-----------------------------|-------------------------------|------------------------------------------|------------------|
| `zaken`      | `create`                    | `zaak`                        | Creates a new zaak                       | First |
| `zaken`      | `create`                    | `status`                      | Updates the zaak status                  | Middle |
| `zaken`      | `create`                    | `zaakinformatieobject`        | Uploads a document to the zaak           | Middle |
| `documenten` | `update`, `partial_update`  | `enkelvoudiginformatieobject` | Sends the newest version of the document | After the middle group |
| `zaken`      | `create`                    | `resultaat`                   | Closes the zaak with a final outcome     | Last |

Within each group a batch keeps the order in which the notifications were received.

The endpoint accepts every action the ZGW APIs publish (`create`, `update`, `partial_update` and `destroy`) on any kanaal and resource, and stores the optional `kenmerken` with the notification. Any other `actie` is rejected with a 422, as is a link that is not on a configured ZGW host.

A notification that is accepted but not processed is stored as ignored, with a reason in `ignored_reason`, and never added to a batch:

- `unsupported_channel`: the kanaal is not `zaken` or `documenten`.
- `unsupported_action`: the combination of `actie` and `resource` is not in the table above. On the `documenten` kanaal that is a `create` or `destroy` of a document (a new document reaches Corsa through its link to a zaak), and anything on `gebruiksrechten` or `verzending`.
- `unknown_zaaktype`: the zaak has a zaaktype without a mapping.
- `inactive_zaaktype`: the zaak has a zaaktype whose mapping is not active.
- `document_not_linked`: a changed document is not linked to any zaak. Once it is linked, the link sends the newest version.
- `document_in_multiple_zaken`: a changed document is linked to more than one zaak with an active mapping. A warning is logged as well.

Ignored notifications keep their full payload, so they can be processed later, for example once a zaaktype mapping has been activated. Processing them again is not built yet.

### Direct route

Every notification is stored with a `route`: `batch` (the default) or `direct`.

A `create:zaakinformatieobject` notification for a document with status `in_bewerking` takes the direct route. On ingest the application fetches the zaakinformatieobject and the document to read the status. Any other status, or a document that cannot be fetched, goes into the batch as before. A notification on the direct route is not added to a batch. The `ProcessDocumentDirectly` job handles it on the `direct` queue:

- When the zaak is in Corsa, the document is added to it right away.
- When the zaak is not in Corsa yet, the notification moves to the batch of the zaak (its route becomes `batch`), so the zaak is created first.
- A connection error, a time-out (408), rate limiting (429) or a server error while fetching from the ZGW APIs or adding the document to Corsa is retried after 30 seconds, 2 minutes and 5 minutes. Any other error fails the job right away. A failed job stays visible in Horizon, and the notification is marked as failed (see [Failed notifications](#failed-notifications)).
- An error while checking whether the zaak is in Corsa counts as "not in Corsa": the notification moves to the batch without a retry.

An `update` or `partial_update` on `enkelvoudiginformatieobject` (kanaal `documenten`) always takes the direct route. On ingest the application looks up the zaak through `GET {zaken}/zaakinformatieobjecten?informatieobject=<document>`, and only zaken whose zaaktype has an active mapping count. With exactly one such zaak the notification is stored on the direct route for that zaak; otherwise it is ignored (see the reasons above). The `ProcessDocumentDirectly` job then:

- fetches the newest version of the document from the Documenten API, and skips it when `document_syncs` already has that `versie` or a newer one;
- waits when `bestandsdelen` is not empty (the upload is not complete yet);
- checks whether the document is in Corsa: first in `document_syncs`, and for a document without an entry (for example one added before the register existed) in the list of documents of the zaak in Corsa (`geefLijstZaakdocumenten`), matched on the identificatie of the document;
- sends it with `updateZaakDocument`, always with the content from the Documenten API and the same metadata as when the document was added, and records the new `versie` in `document_syncs`.

When the document is not in Corsa yet, or its upload is not complete, the job checks again after 30 seconds, 2 minutes and 5 minutes. A document that is still not in Corsa after that moves to the batch of the zaak, where the link that adds it comes first; there the change fails visibly if the document is still missing, and like any failed job in a batch chain it stops the jobs after it, including the resultaat. An upload that is still not complete fails the job. Waiting does not count as an error. A change that ends up in a batch is processed with the same checks; a batch job waits for an incomplete upload the same way as for the lock of a document.

The list of documents in Corsa is read as the zaak object with one `heeftRelevant` per document and the identificatie under `gerelateerde`. That shape still has to be confirmed against a test environment of Corsa. If Corsa answers the list with a fault message, or with an answer without a zaak object, the document counts as not in Corsa (an HTTP or connection error is handled like any other error of the job), so the change ends up failed in the batch rather than being sent under a wrong identificatie.

Every document that is sent to Corsa, on either route, is recorded in `document_syncs` (document URL, zaak, the identificatie sent to Corsa, `versie`, and whether a placeholder was sent). A change updates the entry, and is sent under the identificatie and for the zaak recorded there. A document that is already recorded for the same zaak is not sent again, so a notification that is delivered twice does not add the document twice. The check, the upload and the registration run under a cache lock per document URL, so this also holds when two jobs for the same document run at the same time (a repeated delivery, the direct route and the batch route, or a change while the document is being added). The lock lasts at most 300 seconds, the job time-out in production. A job that does not get the lock tries again 15 seconds later and then finds the document in the register. Waiting for the lock does not count as an error: the direct job still fails at its fourth temporary error, and the batch job at its first error. The lock does not cover a time-out on the upload after which Corsa did store the document: a retry then sends it again.

A document with status `in_bewerking` that has no content yet (no `inhoud`, or an empty download) and no `bestandsdelen` is sent to Corsa as a small placeholder text file. This applies on both routes, so it also covers such a document that ends up in a batch. Any other document without content is still refused. The first change with real content replaces the placeholder. A change that still has no content while the document is `in_bewerking` is skipped and the placeholder stays; a change without content for a document with another status fails.

### Failed notifications

When the job that processes a notification fails for good (after its last attempt, or right away on a permanent error), the notification gets `failed_at`, a short failure description and the id of the failed job. For an HTTP error the description only keeps the status, for a database error only the SQLSTATE; other messages are shortened, with long numbers and e-mail addresses masked. A later run that processes the notification, for example a retry from Horizon, clears these fields again.

The admin panel shows each notification as processed, failed or not processed yet, and links a failed notification to its failed job in Horizon (opening in a new tab). Horizon keeps failed jobs for a limited time (`horizon.trim.failed`, a week by default); after that the link no longer works, but the failure description stays on the notification.

A batch stays `processing` while its chain runs. The last job of the chain marks it as `processed`, so that only happens once every job before it has finished. In a batch chain the jobs after a failed job do not run, the last one included: the batch stays `processing`, the failed notification shows as failed, and the notifications after it stay "not processed yet".

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
| `OPENZAAK_URL` | Root URL of the OpenZaak instance (for example `https://openzaak.example.com/`) |
| `OPENZAAK_CLIENT_ID` | Client ID of this application in OpenZaak |
| `OPENZAAK_CLIENT_SECRET` | Client secret of this application in OpenZaak. Must be at least 32 bytes long. |

The ZGW APIs are called through `woweb/laravel-zgw-client`, configured in `config/zgw.php`. The variables below are optional. When a variable is not set, the value falls back to the `OPENZAAK_*` variables above.

| Variable | Default | Description |
|---|---|---|
| `ZGW_ZAKEN_BASE_URL` | `{OPENZAAK_URL}zaken/api/v1/` | Full base URL of the Zaken API |
| `ZGW_CATALOGI_BASE_URL` | `{OPENZAAK_URL}catalogi/api/v1/` | Full base URL of the Catalogi API |
| `ZGW_DOCUMENTEN_BASE_URL` | `{OPENZAAK_URL}documenten/api/v1/` | Full base URL of the Documenten API |
| `ZGW_CLIENT_ID` | `OPENZAAK_CLIENT_ID` | Client ID used to sign the JWT |
| `ZGW_CLIENT_SECRET` | `OPENZAAK_CLIENT_SECRET` | Client secret used to sign the JWT (at least 32 bytes) |
| `ZGW_USER_ID` | `application` | `user_id` claim, shown in the OpenZaak audit trail |
| `ZGW_USER_REPRESENTATION` | `Application background task` | `user_representation` claim, shown in the OpenZaak audit trail |
| `ZGW_VERSION` | `1.7` | ZGW standard release of the OpenZaak instance (`1.5`, `1.6` or `1.7`) |
| `ZGW_ALLOWED_HOSTS` | empty | Extra hosts the client may call, comma separated. Only needed when OpenZaak returns links on a host other than the base URLs. |

Requests are only sent to the hosts of the base URLs and `ZGW_ALLOWED_HOSTS`. A notification whose `hoofdObject` or `resourceUrl` is on another host is rejected with a 422.

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

Documents on the direct route (new links in bewerking and changes) are processed on the `direct` queue. The Horizon supervisor works on both `direct` and `default` (`config/horizon.php`). With balancing enabled, Horizon keeps at least one worker process per queue, so a direct job does not wait behind a batch chain.

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
