# Batchingsysteem voor Notificaties

## Algemene Beschrijving

Het batchingsysteem groepeert meerdere notificaties die voor dezelfde zaak binnenkomen binnen een instelbaar tijdvenster. Wanneer de timer afloopt, wordt de batch geblokkeerd en worden de notificaties in een specifieke volgorde verwerkt via een jobketen.

## Hoofdcomponenten

### 1. **Batch-model** (`app/Models/Batch.php`)
Vertegenwoordigt een batch notificaties voor een specifieke zaak.

**Velden:**
- `id` (UUID): Unieke identifier
- `zaak_identificatie`: Identificatienummer van de zaak
- `locked_at`: Datum/tijd wanneer de batch werd geblokkeerd
- `processed_at`: Datum/tijd wanneer de batch werd verwerkt
- `status`: Status van de batch (pending, processing, processed, failed); in het paneel Wachtend, In verwerking, Verwerkt en Mislukt
- `created_at, updated_at`: Audittimestamps

**Relaties:**
- `notifications()`: Alle notificaties in de batch

**Voornaamste Methoden:**
- `lockForProcessing()`: Blokkeert de batch en zet de status op processing, met één voorwaardelijke update (alleen als `locked_at` nog leeg is); geeft terug of deze aanroep de batch blokkeerde
- `markProcessed()`: Markeert de batch als verwerkt (zet processed_at en status)
- `markProcessedOnce()`: Idem, maar alleen als de batch nog niet verwerkt is; gebruikt door `MarkBatchProcessed`. Een mislukte batch is niet verwerkt, dus die gaat na een geslaagde herverwerking alsnog naar processed
- `markFailedUnlessProcessed(string $id)`: Zet de batch op failed, alleen als hij nog niet verwerkt is (dezelfde voorwaarde als `markProcessedOnce()`); gebruikt door de catch van de keten
- `isStuck()` en de scope `stuck()`: De batch staat langer dan `batching.stuck_after_minutes` op processing zonder mislukte notificatie
- `isLocked()`: Controleert of de batch geblokkeerd is
- `isProcessed()`: Controleert of de batch verwerkt is
- `hasZaakAangemaakt()`: Controleert of de batch een "zaak aangemaakt"-notificatie bevat
- `getNotificationsSorted()`: Retourneert de notificaties in verwerkingsvolgorde (zie "Volgorde binnen een batch" hieronder)
- `getNotificationTypes()`: Retourneert array van unieke actietypen in de batch

### 2. **Batchingservice** (`app/Services/BatchingService.php`)
Beheert de centrale logica van het batchingsysteem.

**Voornaamste Methoden:**
- `getOrCreateBatch(string $zaakIdentificatie): Batch`: Haalt of maakt een batch voor een zaak. Leest de open batch met een rijvergrendeling, dus roep hem aan in een transactie die ook de notificatie koppelt (zie "Gelijktijdige verwerking" hieronder)
- `addNotificationToBatch(Notification $notification, Batch $batch): void`: Voegt een notificatie aan de batch toe en reset de timer
- `resetTimer(Batch $batch): void`: Stelt de timer voor een batch opnieuw in
- `processBatch(Batch $batch): void`: Verwerkt een batch wanneer de timer afloopt
- `hasActiveTimer(Batch $batch): bool`: Controleert of een batch nog een actieve timer heeft
- `getUnprocessedBatches()`: Haalt alle batches op die klaar zijn voor verwerking (geen actieve timer en niet geblokkeerd)

**Timerfunctionaliteit:**
- Opgeslagen in cache met automatische verloopdatum
- Wordt opnieuw ingesteld telkens wanneer een nieuwe notificatie voor dezelfde zaak binnenkomt
- Instelbaar via `NOTIFICATION_BATCH_TIMEOUT` (standaard 60 seconden)

### 3. **Verwerkingsjobs**

#### DispatchBatch (`app/Jobs/Notifications/DispatchBatch.php`)
Verwerkt een batch wanneer de timer afloopt.

**Logica:**
1. Blokkeert de batch en zet status op 'processing' (`lockForProcessing()`); was de batch al geblokkeerd, dan stopt de job zonder keten
2. Haalt de gesorteerde notificaties op
3. Bepaalt de verwerkingsstrategie:
   - **Met "zaak aangemaakt"**: Maakt een jobketen die de aanmaaknotificatie eerst verwerkt, gevolgd door de rest sequentieel
   - **Zonder "zaak aangemaakt"**: Maakt een jobketen die alle notificaties sequentieel verwerkt (met TODO: verificatie van zaakbestaan in Corsa als eerste stap)
4. Hangt `MarkBatchProcessed` als laatste job aan de keten: de batch blijft op 'processing' en gaat pas naar 'processed' als alle jobs in de keten gelukt zijn (een batch zonder notificaties staat meteen op 'processed')
5. Geeft de keten een `catch()`: mislukt een job definitief, dan stopt de keten en zet de catch de batch op 'failed', tenzij hij al 'processed' is. De catch wordt met de keten geserialiseerd en neemt alleen het id van de batch mee. Lukt de mislukte job later alsnog (opnieuw proberen vanuit Horizon), dan loopt de rest van de keten door en zet `MarkBatchProcessed` de batch van 'failed' op 'processed'

#### HandleNotification (`app/Jobs/Notifications/HandleNotification.php`)
Verwerkt een individuele notificatie.

**Ondersteunde actie-resource combinaties:**
- `create:zaak`: Zaak aangemaakt → `handleZaakAangemaakt()`
- `create:status`: Status aangemaakt → `handleZaakPartialUpdate()`
- `create:zaakinformatieobject`: Document aangemaakt → `handleDocumentAangemaakt()`
- `create:resultaat`: Resultaat aangemaakt → `handleResultaatAangemaakt()`
- `update:enkelvoudiginformatieobject` en `partial_update:enkelvoudiginformatieobject`: Document gewijzigd → `handleDocumentGewijzigd()`, met dezelfde servicemethode en controles als de directe route (`CorsaZaakdmsService::updateDocument()`). Staat het document nog niet in Corsa, dan mislukt de job zichtbaar; is de upload nog niet compleet (`bestandsdelen`), dan wacht de job zoals bij de lock van een document
- Overige: niet ondersteund → `ignoreUnsupportedAction()`

**Stroom:**
1. Match op de combinatie van `actie:resource`
2. Bij een ondersteunde combinatie: voert de overeenkomstige handler uit en markeert de notificatie als verwerkt (`processed = true`, `processed_at = now()`)
3. Bij een niet ondersteunde combinatie: markeert de notificatie als genegeerd (`ignored_at`, `ignored_reason = unsupported_action`), niet als verwerkt. De job faalt niet, zodat de rest van de batch doorloopt. Nieuwe notificaties met zo'n combinatie komen niet meer in een batch; die worden al bij binnenkomst als genegeerd opgeslagen (zie `docs/notificaties.md`)
4. Verhandelt fouten met gedetailleerde logging

#### FlushExpiredBatches (`app/Jobs/Notifications/FlushExpiredBatches.php`)
Triggerjob die batches met verlopen timers opzoekt en verwerkt.

Wordt elke minuut via de scheduler uitgevoerd.

### 4. **Artisan-commando** (`app/Console/Commands/FlushExpiredBatchesCommand.php`)
Commando dat de batchverwerking activeert.

```bash
php artisan notifications:flush-expired-batches
```

Wordt automatisch elke minuut via de scheduler in `routes/console.php` uitgevoerd.

## Volgorde binnen een batch

De volgorde staat op één plek, `Notification::processingGroup()` en de scope `inProcessingOrder()`, en geldt zowel voor de jobketen (`getNotificationsSorted()`) als voor de notificatielijst op de batchpagina:

| Groep | Notificaties |
|---|---|
| 0 | `create:zaak` |
| 1 | alle overige, zoals `create:status` en `create:zaakinformatieobject` |
| 2 | wijzigingen van een document (`update` en `partial_update` op `enkelvoudiginformatieobject`) |
| 3 | `create:resultaat` |

Binnen een groep is de volgorde expliciet die van ontvangst (`created_at`, daarna `id` bij gelijke tijd); voorheen was dat de volgorde die de database teruggaf. Een documentwijziging staat zo na de koppeling die het document toevoegt en vóór het resultaat, waarna de zaak in Corsa afgesloten kan zijn. De sortering loopt in de query, met JSON-paden die de query grammar per database schrijft, zodat ze ook op PostgreSQL klopt.

## Verwerkingsstroom

```
1. Notificatie binnenkomst → IngestNotification job
   ↓
2. BatchingService haalt/maakt een Batch
   ↓
3. Notificatie wordt aan Batch toegevoegd
   ↓
4. Timer wordt ingesteld/opnieuw ingesteld in cache (60 seconden)
   ↓
5. Volgende notificatie voor dezelfde zaak binnenkomst → Timer wordt opnieuw ingesteld
   ↓
6. Timer afgelopen → notifications:flush-expired-batches commando voert uit
   ↓
7. FlushExpiredBatches zoekt batches met verlopen timers
   ↓
8. DispatchBatch haalt de gesorteerde notificaties op
   ↓
9a. Indien "zaak aangemaakt" aanwezig:
    - Maakt jobketen: HandleNotification(create) → HandleNotification(overige) sequentieel
9b. Indien GEEN "zaak aangemaakt":
    - Maakt jobketen met alle HandleNotification jobs sequentieel
    - TODO: Eerste job moet zaakbestaan in Corsa verifiëren
   ↓
10. HandleNotification verhandelt elke notificatie sequentieel
    ↓
11. Batch wordt gemarkeerd als verwerkt (MarkBatchProcessed), of als mislukt als de keten bij een mislukte job stopte
```

## Configuratie

Het bestand `config/batching.php` definieert de systeemparameters:

```php
'batch_timeout' => env('NOTIFICATION_BATCH_TIMEOUT', 60),  // Seconden
'batch_max_size' => env('NOTIFICATION_BATCH_MAX_SIZE', 100),  // Max notificaties
'use_queue' => env('NOTIFICATION_USE_QUEUE', true),  // Gebruik queue
'queue' => env('NOTIFICATION_QUEUE', 'default'),  // Naam van de queue
'stuck_after_minutes' => env('NOTIFICATION_BATCH_STUCK_AFTER_MINUTES', 30),  // Minuten tot "Lang in verwerking"
```

### Omgevingsvariabelen
```env
NOTIFICATION_BATCH_TIMEOUT=60
NOTIFICATION_BATCH_MAX_SIZE=100
NOTIFICATION_USE_QUEUE=true
NOTIFICATION_QUEUE=default
NOTIFICATION_BATCH_STUCK_AFTER_MINUTES=30
```

## Database

### Tabel `batches`
```sql
CREATE TABLE batches (
    id CHAR(36) PRIMARY KEY,
    zaak_identificatie VARCHAR(255),
    locked_at TIMESTAMP NULL,
    processed_at TIMESTAMP NULL,
    status VARCHAR(255) DEFAULT 'pending',  -- pending, processing, processed, failed
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    INDEX (zaak_identificatie),
    INDEX (locked_at),
    INDEX (status)
);
```

### Wijzigingen aan `notifications`
```sql
ALTER TABLE notifications ADD COLUMN batch_id CHAR(36) NULL;
ALTER TABLE notifications ADD FOREIGN KEY (batch_id) REFERENCES batches(id) ON DELETE CASCADE;
ALTER TABLE notifications ADD INDEX (batch_id);
ALTER TABLE notifications ADD COLUMN processed_at TIMESTAMP NULL;
ALTER TABLE notifications ADD COLUMN ignored_at TIMESTAMP NULL;
ALTER TABLE notifications ADD COLUMN ignored_reason VARCHAR(255) NULL;  -- unsupported_channel, unsupported_action, unknown_zaaktype, inactive_zaaktype
ALTER TABLE notifications ADD INDEX (ignored_reason);
ALTER TABLE notifications ADD INDEX (ignored_at);
ALTER TABLE notifications ALTER COLUMN zaak_identificatie DROP NOT NULL;
```

## Migraties

Om de migraties toe te passen:

```bash
php artisan migrate
```

Gemaakte migraties:
- `2025_11_28_132300_create_batches_table.php`: Maakt batchtabel aan
- `2025_11_28_132301_add_batch_id_to_notifications_table.php`: Voegt relatie toe
- `2026_04_21_125920_add_processed_at_to_notifications_table.php`: Voegt `processed_at` timestamp toe aan notificaties
- `2026_10_23_090000_add_ignored_columns_to_notifications_table.php`: Voegt `ignored_at` en `ignored_reason` toe (beide met een index) en maakt `zaak_identificatie` nullable

## Gebruiksvoorbeelden

### Handmatige Verwerking
```php
use App\Services\BatchingService;
use App\Models\Batch;

$batchingService = app(BatchingService::class);

// Haal of maak een batch
$batch = $batchingService->getOrCreateBatch('zaak-123');

// Verwerk een batch handmatig
$batchingService->processBatch($batch);
```

### Batchstatus Controleren
```php
use App\Models\Batch;

$batch = Batch::findOrFail($batchId);

// Controleer of geblokkeerd
if ($batch->isLocked()) {
    echo "Batch geblokkeerd";
}

// Controleer of verwerkt
if ($batch->isProcessed()) {
    echo "Batch verwerkt";
}

// Bekijk notificaties
$notifications = $batch->notifications()->get();

// Bekijk notificatietypen
$types = $batch->getNotificationTypes();
```

## Gelijktijdige verwerking

Er kunnen twee jobs tegelijk voor dezelfde batch lopen, bijvoorbeeld als de queue vol staat en de scheduler de batch na een minuut opnieuw klaarzet. Daarom:

- **Blokkeren is één voorwaardelijke update.** `lockForProcessing()` zet `locked_at` en `processing` alleen als `locked_at` nog leeg is. Alleen de job waarvan de update de rij raakte, bouwt de keten; de andere logt "Attempted to process already locked batch" en stopt.
- **Toevoegen en blokkeren sluiten elkaar uit.** `IngestNotification` en de terugval van de directe route (`ProcessDocumentDirectly::moveToBatch()`) zoeken de open batch en koppelen de notificatie in één transactie. `getOrCreateBatch()` leest de batch daarin met `lockForUpdate()`. Op PostgreSQL wacht de voorwaardelijke update van `lockForProcessing()` op die rijvergrendeling. Is de notificatie eerst gekoppeld, dan leest `processBatch()` haar daarna mee. Is de batch eerst geblokkeerd, dan vindt `getOrCreateBatch()` hem na het wachten niet meer als open batch en komt de notificatie in een nieuwe batch.
- **Dubbel uitvoeren is onschadelijk.** `HandleNotification` stopt meteen, met een logregel, als de notificatie al verwerkt of genegeerd is, en laat de rest van de keten doorgaan. `MarkBatchProcessed` houdt het tijdstip waarop de batch voor het eerst verwerkt werd.

SQLite (de testsuite) kent geen rijvergrendeling; de tests tonen de logica, niet twee workers tegelijk.

## Mislukte en lang lopende batches

- **Mislukt.** Stopt de keten bij een definitief mislukte job, dan zet de `catch()` van de keten de batch op `failed` (rood, "Mislukt"), met dezelfde voorwaarde als `markProcessedOnce()`: een batch die al `processed` is blijft dat. Wordt de mislukte job vanuit Horizon opnieuw geprobeerd en lukt hij, dan loopt de rest van de keten door en zet `MarkBatchProcessed` de batch op `processed`. Tot dan blijft hij `failed`.
- **Lang in verwerking.** Een batch kan ook op `processing` blijven staan zonder mislukte job, bijvoorbeeld als een job uit de queue verdwijnt. Staat hij langer dan `batching.stuck_after_minutes` (`NOTIFICATION_BATCH_STUCK_AFTER_MINUTES`, standaard 30) op `processing`, gerekend vanaf `locked_at`, en heeft hij geen mislukte notificatie, dan toont het paneel "Lang in verwerking" (oranje met een klok) in het overzicht en op de batchpagina, met een filter. Het signaal wordt bij het lezen afgeleid; de status verandert niet en er draait geen geplande taak. Een job in de keten wacht hooguit 20 keer 15 seconden (5 minuten) op een document dat een andere job verstuurt, en één poging duurt hooguit de worker-time-out (standaard 60 seconden, in productie 5 minuten, zie `config/horizon.php`), dus een gewone keten van een paar jobs is ruim binnen 30 minuten klaar, ook met wat wachttijd in de queue. Zijn batches met veel documenten normaal, verhoog dan de waarde.

## Toekomstige Verbeteringen

1. Implementeer verificatie van zaakbestaan in Corsa (bij batches zonder zaak aangemaakt)
2. Implementeer daadwerkelijke logica in de handlers:
   - `handleZaakAangemaakt()`: Zaak aanmaken in Corsa
   - `handleZaakPartialUpdate()`: Status bijwerken in Corsa
   - `handleDocumentAangemaakt()`: Document toevoegen in Corsa
3. Voeg retrybehandeling voor mislukte notificaties toe
4. Implementeer opschoning van oude verwerkte batches
5. Voeg metreken en prestatiemonitoring toe
6. Uitbreiding naar meer actie-resource combinaties (bijv. update:zaak, delete:zaak)
