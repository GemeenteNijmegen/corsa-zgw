# Test Plan

This document tracks the automated test coverage plan for corsa-zgw. Tests are grouped by priority and area.

## Status

| Area | Tests written | Branch / PR |
|---|---|---|
| `BatchingService` | ✅ 26 tests | `test/batching` |
| Everything else | ❌ | — |

---

## Tier 1 — Critical business logic

### `CorsaZaakdmsService` (~25 tests)

The largest untested class (499 lines). Covers all external I/O with OpenZaak and Corsa.

- `processZaakAangemaakt` fetches zaak and zaaktype, maps data, creates zaak in Corsa
- `processZaakAangemaakt` skips creation when zaak already exists in Corsa
- `processZaakAangemaakt` handles missing zaak URL
- `processZaakPartialUpdate` updates status correctly
- `processZaakPartialUpdate` detects and handles end status
- `processZaakPartialUpdate` fails when zaak is missing in Corsa
- `processDocumentAangemaakt` downloads, uploads, and cleans up temp file
- `processDocumentAangemaakt` handles missing informatieobject URL
- `checkZaakExistenceInCorsa` returns true / false
- `mapInitiator` maps `natuurlijk_persoon` type
- `mapInitiator` maps `niet_natuurlijk_persoon` type
- `mapInitiator` returns null for unknown type
- `formatZaakdmsDate` converts dates to `Ymd`
- `addExpandToUrl` appends expand parameter correctly
- `mapVertrouwelijkAanduiding` normalises all permission levels
- `writeTempDocument` creates file and returns path
- `resolveZaakUrlFromNotification` resolves URL from notification payload
- `mapZaakToCorsaCreateOptions` maps all required fields

### `HandleNotification` job (~8 tests)

- Dispatches `create:zaak` to `handleZaakAangemaakt`
- Dispatches `create:status` to `handleZaakPartialUpdate`
- Dispatches `create:zaakinformatieobject` to `handleDocumentAangemaakt`
- Logs a warning and does not throw for unknown action/resource combinations
- Marks notification as `processed = true` on success
- Re-throws exception and logs error on failure
- Does not mark notification as processed when an exception is thrown

---

## Tier 2 — Core features

### `Api/Notifications` controller (~5 tests)

- Valid payload is accepted, `IngestNotification` job is dispatched
- Returns 200 with the correct response shape
- Unauthenticated request is rejected (401)
- Request with missing required fields is rejected (422)

### `NotificationRequest` validation (~12 tests)

Use a dataset for field-level rules.

- `actie` — required, string, valid enum value
- `kanaal` — required, string, valid enum value
- `resource` — required, string, valid enum value
- `hoofdObject` — required, valid URL
- `resourceUrl` — required, valid URL
- `aanmaakdatum` — required, valid date
- Invalid enum values are rejected
- Malformed URLs are rejected
- Invalid date formats are rejected
- All valid combinations pass

### `Batch` model (~8 tests)

- `isLocked()` returns false when `locked_at` is null
- `isLocked()` returns true when `locked_at` is set
- `isProcessed()` returns false / true based on `processed_at`
- `lock()` sets `locked_at` to now
- `markProcessed()` sets `processed_at` and `status = processed`
- `hasZaakAangemaakt()` returns true when a `create:zaak` notification is present
- `hasZaakAangemaakt()` returns false when no `create:zaak` notification is present
- `getNotificationsSorted()` places `create:zaak` before all other notifications
- `getNotificationTypes()` returns unique actie values

---

## Tier 3 — Supporting

### `DispatchBatch` job (~4 tests)

- `handle()` calls `BatchingService::processBatch()` with a fresh batch instance
- `handle()` resolves `BatchingService` from the service container
- Exception propagates out of `handle()`

### `FlushExpiredBatches` job (~4 tests)

- Dispatches one `DispatchBatch` job per unprocessed batch
- Dispatches nothing when there are no unprocessed batches
- Jobs are dispatched on the configured queue

### `FlushExpiredBatchesCommand` command (~3 tests)

- Command exits with `SUCCESS`
- Dispatches `FlushExpiredBatches` job
- Output message confirms dispatch

### `Notification` model (~3 tests)

- `batch()` relationship returns the associated `Batch`
- `notification` column is cast to array
- `processed` column is cast to boolean

### `User` model (~4 tests)

- `canAccessPanel()` returns true for any panel
- Factory creates a valid user with hashed password
- API token can be created and used for authentication

### Value objects (~8 tests)

- `OpenNotification::toArray()` returns all fields
- `Zaak` constructor maps all properties from raw API data
- `Zaak::setZaakAddresses()` extracts addresses from `adressen`
- `Zaak::toArray()` serialises correctly
- `Rol::toArray()` serialises correctly
- `ZaakEigenschap::toArray()` serialises correctly

### Filament `UserResource` (~10 tests)

- `ListUsers` page renders and shows users
- `CreateUser` form passes validation with valid data
- `CreateUser` form fails validation on missing name / invalid email
- `EditUser` page loads the correct user
- `EditUser` saves changes and redirects
- `TokensRelationManager` lists existing tokens
- Admin can delete a user via the table action

### Routes (~3 tests)

- `POST /api/v1/notifications` requires a valid Sanctum token
- `POST /api/v1/notifications` rejects unsupported HTTP methods
- `GET /` returns a 200 response

---

## Running the test suite

```bash
# All tests
php artisan test --compact

# Single file
php artisan test --compact tests/Feature/BatchingServiceTest.php

# Filtered
php artisan test --compact --filter=BatchingService
```
