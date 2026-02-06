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
- `status`: Status van de batch (pending, processing, processed)
- `created_at, updated_at`: Audittimestamps

**Relaties:**
- `notifications()`: Alle notificaties in de batch

**Voornaamste Methoden:**
- `lock()`: Blokkeert de batch (zet locked_at timestamp)
- `markProcessed()`: Markeert de batch als verwerkt (zet processed_at en status)
- `isLocked()`: Controleert of de batch geblokkeerd is
- `isProcessed()`: Controleert of de batch verwerkt is
- `hasZaakAangemaakt()`: Controleert of de batch een "zaak aangemaakt"-notificatie bevat
- `getNotificationsSorted()`: Retourneert notificaties gesorteerd (zaak:create eerst)
- `getNotificationTypes()`: Retourneert array van unieke actietypen in de batch

### 2. **Batchingservice** (`app/Services/BatchingService.php`)
Beheert de centrale logica van het batchingsysteem.

**Voornaamste Methoden:**
- `getOrCreateBatch(string $zaakIdentificatie): Batch`: Haalt of maakt een batch voor een zaak
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

#### ProcessBatch (`app/Jobs/ProcessBatch.php`)
Verwerkt een batch wanneer de timer afloopt.

**Logica:**
1. Blokkeert de batch en zet status op 'processing'
2. Haalt de gesorteerde notificaties op
3. Bepaalt de verwerkingsstrategie:
   - **Met "zaak aangemaakt"**: Maakt een jobketen die de aanmaaknotificatie eerst verwerkt, gevolgd door de rest sequentieel
   - **Zonder "zaak aangemaakt"**: Maakt een jobketen die alle notificaties sequentieel verwerkt (met TODO: verificatie van zaakbestaan in Corsa als eerste stap)
4. Markeert de batch als verwerkt

#### ProcessNotification (`app/Jobs/ProcessNotification.php`)
Verwerkt een individuele notificatie.

**Ondersteunde actie-resource combinaties:**
- `create:zaak`: Zaak aangemaakt → `handleZaakAangemaakt()`
- `create:status`: Status aangemaakt → `handleZaakPartialUpdate()`
- `create:zaakinformatieobject`: Document aangemaakt → `handleDocumentAangemaakt()`
- Overige: Onbekende actie → `handleUnknownAction()`

**Stroom:**
1. Match op de combinatie van `actie:resource`
2. Voert de overeenkomstige handler uit
3. Markeert de notificatie als verwerkt
4. Verhandelt fouten met gedetailleerde logging

#### TriggerBatchProcessing (`app/Jobs/TriggerBatchProcessing.php`)
Triggerjob die batches met verlopen timers opzoekt en verwerkt.

Wordt elke minuut via de scheduler uitgevoerd.

### 4. **Artisan-commando** (`app/Console/Commands/ProcessNotificationBatches.php`)
Commando dat de batchverwerking activeert.

```bash
php artisan notifications:process-batches
```

Wordt automatisch elke minuut via de scheduler in `routes/console.php` uitgevoerd.

## Verwerkingsstroom

```
1. Notificatie binnenkomst → CheckIncomingNotification job
   ↓
2. BatchingService haalt/maakt een Batch
   ↓
3. Notificatie wordt aan Batch toegevoegd
   ↓
4. Timer wordt ingesteld/opnieuw ingesteld in cache (60 seconden)
   ↓
5. Volgende notificatie voor dezelfde zaak binnenkomst → Timer wordt opnieuw ingesteld
   ↓
6. Timer afgelopen → ProcessNotificationBatches commando voert uit
   ↓
7. TriggerBatchProcessing zoekt batches met verlopen timers
   ↓
8. ProcessBatch haalt de gesorteerde notificaties op
   ↓
9a. Indien "zaak aangemaakt" aanwezig:
    - Maakt jobketen: ProcessNotification(create) → ProcessNotification(overige) sequentieel
9b. Indien GEEN "zaak aangemaakt":
    - Maakt jobketen met alle ProcessNotification jobs sequentieel
    - TODO: Eerste job moet zaakbestaan in Corsa verifiëren
   ↓
10. ProcessNotification verhandelt elke notificatie sequentieel
    ↓
11. Batch wordt gemarkeerd als verwerkt
```

## Configuratie

Het bestand `config/batching.php` definieert de systeemparameters:

```php
'batch_timeout' => env('NOTIFICATION_BATCH_TIMEOUT', 60),  // Seconden
'batch_max_size' => env('NOTIFICATION_BATCH_MAX_SIZE', 100),  // Max notificaties
'use_queue' => env('NOTIFICATION_USE_QUEUE', true),  // Gebruik queue
'queue' => env('NOTIFICATION_QUEUE', 'default'),  // Naam van de queue
```

### Omgevingsvariabelen
```env
NOTIFICATION_BATCH_TIMEOUT=60
NOTIFICATION_BATCH_MAX_SIZE=100
NOTIFICATION_USE_QUEUE=true
NOTIFICATION_QUEUE=default
```

## Database

### Tabel `batches`
```sql
CREATE TABLE batches (
    id CHAR(36) PRIMARY KEY,
    zaak_identificatie VARCHAR(255),
    locked_at TIMESTAMP NULL,
    processed_at TIMESTAMP NULL,
    status VARCHAR(255) DEFAULT 'pending',  -- pending, processing, processed
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
```

## Migraties

Om de migraties toe te passen:

```bash
php artisan migrate
```

Gemaakte migraties:
- `2025_11_28_132300_create_batches_table.php`: Maakt batchtabel aan
- `2025_11_28_132301_add_batch_id_to_notifications_table.php`: Voegt relatie toe

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
