User: Werk onderstaande beschrijving uit als een sequence diagram, en eventueel visueel op een zinnige manier:
Verwerking notificaties open zaak naar Corsa
Volgordelijkheid van berichten
Omdat notificaties in onvoorspelbare volgorde binnenkomen, én verwerking tijd kost, moeten notificaties (soms) in een specifieke volgorde worden verwerkt.
Daarnaast is het zo dat onvoorspelbaar is welke notificaties binnenkomen en binnen moeten zijn voor een taak gestart kan worden. Daarom verwerken we
notificaties als volgt:
Bij elke notificatie die binnenkomt checken we het zaaknummer
Voor elk zaaknummer starten we bij een binnengekomen notificatie een timer
Als een nieuwe notificatie binnenkomt voor die zaak terwijl de timer loopt herstarten we de timer
Als de timer afloopt starten we verwerking van de notificaties, op een logische volgorde
 Voor het bepalen van de volgorde hanteren we de volgende types notificaties die we willen verwerken:
Zaak aangemaakt
Zaakstatus aan zaak toegevoegd
Rol aan zaak toegevoegd
Document aan zaak toegevoegd
 De 'zaak aangemaakt' notificatie moet altijd als eerste worden verwerkt. Andere notificaties kunnen daarna parallel worden verwerkt. Als in een
notificatiebatch (gedefinieerd als de set notificaties die binnen een timer-periode binnen zijn) géén zaak aangemaakt zit kunnen de notificaties parallel
worden verwerkt, anders moet eerst de 'zaak aangemaakt'-notificatie worden afgehandeld.
Afhandeling
Van elke batch wordt een 'job chain' gedefinieerd in de applicatie (Laravel).
Model: # Sequence Diagram: Verwerking Notificaties Open Zaak naar Corsa

Ik zal dit uitwerken in zowel een sequence diagram als een visueel overzicht van de verschillende scenario's.

## 1. Sequence Diagram

```
┌─────────────┐  ┌──────────────┐  ┌─────────────┐  ┌──────────────┐  ┌─────────────┐
│  Open Zaak  │  │  Notificatie │  │   Timer     │  │    Batch     │  │   Corsa     │
│             │  │   Handler    │  │   Service   │  │  Processor   │  │  (Laravel)  │
└──────┬──────┘  └──────┬───────┘  └──────┬──────┘  └──────┬───────┘  └──────┬──────┘
       │                │                 │                │                 │
       │ Notificatie 1  │                 │                │                 │
       │ (Zaak ID: 123) │                 │                │                 │
       ├───────────────>│                 │                │                 │
       │                │                 │                │                 │
       │                │ Check zaak-     │                │                 │
       │                │ nummer          │                │                 │
       │                ├─────────┐       │                │                 │
       │                │         │       │                │                 │
       │                │<────────┘       │                │                 │
       │                │                 │                │                 │
       │                │ Start timer     │                │                 │
       │                │ (Zaak 123)      │                │                 │
       │                ├────────────────>│                │                 │
       │                │                 │                │                 │
       │                │            Timer loopt...        │                 │
       │                │                 │                │                 │
       │ Notificatie 2  │                 │                │                 │
       │ (Zaak ID: 123) │                 │                │                 │
       ├───────────────>│                 │                │                 │
       │                │                 │                │                 │
       │                │ Check zaak-     │                │                 │
       │                │ nummer (123)    │                │                 │
       │                ├─────────┐       │                │                 │
       │                │         │       │                │                 │
       │                │<────────┘       │                │                 │
       │                │                 │                │                 │
       │                │ Herstart timer  │                │                 │
       │                │ (Zaak 123)      │                │                 │
       │                ├────────────────>│                │                 │
       │                │                 │                │                 │
       │ Notificatie 3  │                 │                │                 │
       │ (Zaak ID: 123) │                 │                │                 │
       ├───────────────>│                 │                │                 │
       │                │                 │                │                 │
       │                │ Check zaak-     │                │                 │
       │                │ nummer (123)    │                │                 │
       │                ├─────────┐       │                │                 │
       │                │         │       │                │                 │
       │                │<────────┘       │                │                 │
       │                │                 │                │                 │
       │                │ Herstart timer  │                │                 │
       │                │ (Zaak 123)      │                │                 │
       │                ├────────────────>│                │                 │
       │                │                 │                │                 │
       │                │            Timer loopt...        │                 │
       │                │                 │                │                 │
       │                │                 │ Timer afgelopen│                 │
       │                │                 │ (Zaak 123)     │                 │
       │                │<────────────────┤                │                 │
       │                │                 │                │                 │
       │                │ Verwerk batch   │                │                 │
       │                │ (Zaak 123)      │                │                 │
       │                ├────────────────────────────────>│                 │
       │                │                 │                │                 │
       │                │                 │    Analyseer batch               │
       │                │                 │    en sorteer notificaties       │
       │                │                 │                ├─────────┐       │
       │                │                 │                │         │       │
       │                │                 │                │<────────┘       │
       │                │                 │                │                 │
       │                │                 │    Bevat "Zaak aangemaakt"?     │
       │                │                 │                ├─────────┐       │
       │                │                 │                │   Ja    │       │
       │                │                 │                │<────────┘       │
       │                │                 │                │                 │
       │                │                 │    Verwerk eerst:               │
       │                │                 │    "Zaak aangemaakt"            │
       │                │                 │                ├────────────────>│
       │                │                 │                │                 │
       │                │                 │                │ Create Job Chain│
       │                │                 │                │ (Zaak aangemaakt)
       │                │                 │                │                 ├───┐
       │                │                 │                │                 │   │
       │                │                 │                │                 │<──┘
       │                │                 │                │                 │
       │                │                 │                │   Job voltooid  │
       │                │                 │                │<────────────────┤
       │                │                 │                │                 │
       │                │                 │    Verwerk parallel:            │
       │                │                 │    - Zaakstatus                 │
       │                │                 │    - Rol                        │
       │                │                 │    - Document                   │
       │                │                 │                ├────────────────>│
       │                │                 │                │                 │
       │                │                 │                │ Create Job Chain│
       │                │                 │                │ (parallel jobs) │
       │                │                 │                │                 ├───┐
       │                │                 │                │                 │   │
       │                │                 │                │  ┌──────────┐  │   │
       │                │                 │                │  │Zaakstatus│  │   │
       │                │                 │                │  └──────────┘  │   │
       │                │                 │                │  ┌──────────┐  │   │
       │                │                 │                │  │   Rol    │  │   │
       │                │                 │                │  └──────────┘  │   │
       │                │                 │                │  ┌──────────┐  │   │
       │                │                 │                │  │ Document │  │   │
       │                │                 │                │  └──────────┘  │<──┘
       │                │                 │                │                 │
       │                │                 │                │  Jobs voltooid  │
       │                │                 │                │<────────────────┤
       │                │                 │                │                 │
       │                │                 │    Batch voltooid               │
       │                │<────────────────────────────────┤                 │
       │                │                 │                │                 │
```

## 2. Visueel Overzicht: Scenario's

### **Scenario A: Batch MET "Zaak aangemaakt"**

```
┌─────────────────────────────────────────────────────────────────┐
│                    TIMER PERIODE (Zaak 123)                     │
│                                                                  │
│  Notificatie 1  →  Notificatie 2  →  Notificatie 3  →  ...     │
│  (Zaak Status)     (Rol)             (Zaak aangemaakt)          │
│        ↓               ↓                    ↓                    │
│        └───────────────┴────────────────────┘                   │
│                         │                                        │
└─────────────────────────┼────────────────────────────────────────┘
                          ↓
              ┌───────────────────────┐
              │   BATCH PROCESSOR     │
              │   Sorteer op type     │
              └───────────┬───────────┘
                          ↓
              ┌───────────────────────┐
              │   VOLGORDE BEPALING   │
              └───────────┬───────────┘
                          ↓
         ┌────────────────────────────────────┐
         │                                    │
         ↓                                    ↓
    ┌─────────┐                    ┌──────────────────────┐
    │  STAP 1 │                    │       STAP 2         │
    │ (Eerst) │                    │     (Parallel)       │
    └────┬────┘                    └──────────┬───────────┘
         │                                    │
         ↓                                    ↓
┌─────────────────┐              ┌───────────────────────────┐
│ Zaak aangemaakt │              │  ┌─────────────────────┐  │
└─────────────────┘              │  │   Zaakstatus        │  │
                                 │  └─────────────────────┘  │
                                 │  ┌─────────────────────┐  │
                                 │  │   Rol               │  │
                                 │  └─────────────────────┘  │
                                 │  ┌─────────────────────┐  │
                                 │  │   Document          │  │
                                 │  └─────────────────────┘  │
                                 └───────────────────────────┘
```

### **Scenario B: Batch ZONDER "Zaak aangemaakt"**

```
┌─────────────────────────────────────────────────────────────────┐
│                    TIMER PERIODE (Zaak 456)                     │
│                                                                  │
│  Notificatie 1  →  Notificatie 2  →  Notificatie 3  →  ...     │
│  (Zaak Status)     (Rol)             (Document)                 │
│        ↓               ↓                    ↓                    │
│        └───────────────┴────────────────────┘                   │
│                         │                                        │
└─────────────────────────┼────────────────────────────────────────┘
                          ↓
              ┌───────────────────────┐
              │   BATCH PROCESSOR     │
              │   Sorteer op type     │
              └───────────┬───────────┘
                          ↓
              ┌───────────────────────┐
              │   VOLGORDE BEPALING   │
              │  Geen "Zaak           │
              │   aangemaakt"         │
              └───────────┬───────────┘
                          ↓
              ┌───────────────────────┐
              │    ALLES PARALLEL     │
              └───────────┬───────────┘
                          ↓
         ┌────────────────┼───────────────────┐
         │                │                   │
         ↓                ↓                   ↓
  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐
  │ Zaakstatus  │  │     Rol     │  │  Document   │
  └─────────────┘  └─────────────┘  └─────────────┘
```

## 3. Timer Mechanisme Visueel

```
Tijdlijn →

Notif 1        Notif 2           Notif 3              Timer
  ↓              ↓                 ↓                   afgelopen
  │              │                 │                      │
  ├──────────────┼─────────────────┼──────────────────────┤
  │              │                 │                      │
  │◄─── T ──────►│                 │                      │
  │              │◄──── T ────────►│                      │
  │              │                 │◄──────── T ─────────►│
  │              │                 │                      │
  │              │                 │                      ↓
  │              │                 │              ┌───────────────┐
  │              │                 │              │   Verwerk     │
  │              │                 │              │   Batch       │
  │              │                 │              └───────────────┘

T = Timer periode (bijv. 5 seconden)
Bij elke nieuwe notificatie: timer reset
```

## 4. State Diagram: Notificatie Verwerking

```
                    ┌──────────────────┐
                    │   Notificatie    │
                    │   Ontvangen      │
                    └────────┬─────────┘
                             │
                             ↓
                    ┌──────────────────┐
                    │  Check Zaak-     │
                    │  nummer          │
                    └────────┬─────────┘
                             │
                ┌────────────┴────────────┐
                │                         │
                ↓                         ↓
      ┌──────────────────┐      ┌──────────────────┐
      │  Timer loopt     │      │  Start nieuwe    │
      │  al voor zaak    │      │  timer           │
      └────────┬─────────┘      └────────┬─────────┘
               │                         │
               ↓                         │
      ┌──────────────────┐              │
      │  Herstart        │              │
      │  timer           │              │
      └────────┬─────────┘              │
               │                         │
               └────────────┬────────────┘
                            │
                            ↓
                   ┌──────────────────┐
                   │  Wacht op timer  │
                   │  afloop          │
                   └────────┬─────────┘
                            │
                            ↓
                   ┌──────────────────┐
                   │  Timer           │
                   │  afgelopen       │
                   └────────┬─────────┘
                            │
                            ↓
                   ┌──────────────────┐
                   │  Analyseer       │
                   │  batch           │
                   └────────┬─────────┘
                            │
                ┌───────────┴───────────┐
                │                       │
                ↓                       ↓
      ┌──────────────────┐    ┌──────────────────┐
      │  Bevat "Zaak     │    │  Bevat GEEN      │
      │  aangemaakt"     │    │  "Zaak           │
      │                  │    │  aangemaakt"     │
      └────────┬─────────┘    └────────┬─────────┘
               │                       │
               ↓                       ↓
      ┌──────────────────┐    ┌──────────────────┐
      │  Sequentieel +   │    │  Alles parallel  │
      │  dan parallel    │    │                  │
      └────────┬─────────┘    └────────┬─────────┘
               │                       │
               └───────────┬───────────┘
                           │
                           ↓
                  ┌──────────────────┐
                  │  Maak Job Chain  │
                  │  in Laravel      │
                  └────────┬─────────┘
                           │
                           ↓
                  ┌──────────────────┐
                  │  Verwerking      │
                  │  voltooid        │
                  └──────────────────┘
```

## 5. Job Chain Structuur (Laravel)

```
┌─────────────────────────────────────────────────────────────┐
│                     JOB CHAIN (Laravel)                     │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│  IF (batch bevat "Zaak aangemaakt"):                       │
│                                                              │
│    ┌──────────────────────────────────────────────┐        │
│    │  Job 1: ProcessZaakAangemaaktNotification    │        │
│    │  Priority: HIGH                              │        │
│    │  Status: MUST_COMPLETE_FIRST                 │        │
│    └────────────────┬─────────────────────────────┘        │
│                     │                                       │
│                     ↓ (wait for completion)                │
│                     │                                       │
│    ┌────────────────┴─────────────────────────────┐        │
│    │         PARALLEL JOBS (Batch)                │        │
│    │                                               │        │
│    │  ┌─────────────────────────────────────┐    │        │
│    │  │ Job 2a: ProcessZaakstatusNotif     │    │        │
│    │  └─────────────────────────────────────┘    │        │
│    │                                               │        │
│    │  ┌─────────────────────────────────────┐    │        │
│    │  │ Job 2b: ProcessRolNotification      │    │        │
│    │  └─────────────────────────────────────┘    │        │
│    │                                               │        │
│    │  ┌─────────────────────────────────────┐    │        │
│    │  │ Job 2c: ProcessDocumentNotification │    │        │
│    │  └─────────────────────────────────────┘    │        │
│    │                                               │        │
│    └───────────────────────────────────────────────┘        │
│                                                              │
│  ELSE (geen "Zaak aangemaakt"):                            │
│                                                              │
│    ┌───────────────────────────────────────────────┐       │
│    │         ALLE JOBS PARALLEL                    │       │
│    │                                                │       │
│    │  ┌─────────────────────────────────────┐     │       │
│    │  │ Job 1: ProcessZaakstatusNotif      │     │       │
│    │  └─────────────────────────────────────┘     │       │
│    │                                                │       │
│    │  ┌─────────────────────────────────────┐     │       │
│    │  │ Job 2: ProcessRolNotification       │     │       │
│    │  └─────────────────────────────────────┘     │       │
│    │                                                │       │
│    │  ┌─────────────────────────────────────┐     │       │
│    │  │ Job 3: ProcessDocumentNotification  │     │       │
│    │  └─────────────────────────────────────┘     │       │
│    │                                                │       │
│    └────────────────────────────────────────────────┘       │
│                                                              │
└─────────────────────────────────────────────────────────────┘
```

## 6. Data Model: Notificatie Batch

```
┌────────────────────────────────────────┐
│       NotificationBatch                │
├────────────────────────────────────────┤
│ - zaak_id: string                      │
│ - created_at: timestamp                │
│ - timer_started_at: timestamp          │
│ - timer_expires_at: timestamp          │
│ - status: enum (pending/processing)    │
│ - notifications: array                 │
└─────────────────┬──────────────────────┘
                  │
                  │ has many
                  │
                  ↓
┌────────────────────────────────────────┐
│       Notification                     │
├────────────────────────────────────────┤
│ - id: int                              │
│ - batch_id: int                        │
│ - type: enum                           │
│   ├─ zaak_aangemaakt                   │
│   ├─ zaakstatus_toegevoegd             │
│   ├─ rol_toegevoegd                    │
│   └─ document_toegevoegd               │
│ - payload: json                        │
│ - received_at: timestamp               │
│ - processed: boolean                   │
│ - processing_order: int                │
└────────────────────────────────────────┘
```

Deze uitwerking geeft een volledig beeld van het notificatie-verwerkingsproces met alle belangrijke aspecten: timing, volgordelijkheid, en de specifieke handling van verschillende scenario's.
User: Pas de diagrammen aan zodat ze in mermaid syntax staan
Model: # Sequence Diagram: Verwerking Notificaties Open Zaak naar Corsa (Mermaid)

## 1. Sequence Diagram - Hoofdproces

```mermaid
sequenceDiagram
    participant OZ as Open Zaak
    participant NH as Notificatie Handler
    participant TS as Timer Service
    participant BP as Batch Processor
    participant LC as Corsa (Laravel)

    OZ->>NH: Notificatie 1 (Zaak ID: 123)
    NH->>NH: Check zaaknummer
    NH->>TS: Start timer (Zaak 123)
    Note over TS: Timer loopt...
    
    OZ->>NH: Notificatie 2 (Zaak ID: 123)
    NH->>NH: Check zaaknummer (123)
    NH->>TS: Herstart timer (Zaak 123)
    Note over TS: Timer herstart...
    
    OZ->>NH: Notificatie 3 (Zaak ID: 123)
    NH->>NH: Check zaaknummer (123)
    NH->>TS: Herstart timer (Zaak 123)
    Note over TS: Timer loopt...
    
    TS->>NH: Timer afgelopen (Zaak 123)
    NH->>BP: Verwerk batch (Zaak 123)
    
    BP->>BP: Analyseer batch en sorteer notificaties
    BP->>BP: Bevat "Zaak aangemaakt"? Ja
    
    BP->>LC: Verwerk eerst: "Zaak aangemaakt"
    LC->>LC: Create Job Chain<br/>(Zaak aangemaakt)
    LC->>BP: Job voltooid
    
    BP->>LC: Verwerk parallel:<br/>- Zaakstatus<br/>- Rol<br/>- Document
    
    par Parallel verwerking
        LC->>LC: Job: Zaakstatus
    and
        LC->>LC: Job: Rol
    and
        LC->>LC: Job: Document
    end
    
    LC->>BP: Jobs voltooid
    BP->>NH: Batch voltooid
```

## 2. Sequence Diagram - Scenario ZONDER "Zaak aangemaakt"

```mermaid
sequenceDiagram
    participant OZ as Open Zaak
    participant NH as Notificatie Handler
    participant TS as Timer Service
    participant BP as Batch Processor
    participant LC as Corsa (Laravel)

    OZ->>NH: Notificatie 1 (Zaak ID: 456, Zaakstatus)
    NH->>TS: Start timer (Zaak 456)
    
    OZ->>NH: Notificatie 2 (Zaak ID: 456, Rol)
    NH->>TS: Herstart timer (Zaak 456)
    
    OZ->>NH: Notificatie 3 (Zaak ID: 456, Document)
    NH->>TS: Herstart timer (Zaak 456)
    
    TS->>NH: Timer afgelopen (Zaak 456)
    NH->>BP: Verwerk batch (Zaak 456)
    
    BP->>BP: Analyseer batch
    BP->>BP: Bevat "Zaak aangemaakt"? Nee
    
    BP->>LC: Verwerk alles parallel
    
    par Parallel verwerking
        LC->>LC: Job: Zaakstatus
    and
        LC->>LC: Job: Rol
    and
        LC->>LC: Job: Document
    end
    
    LC->>BP: Jobs voltooid
    BP->>NH: Batch voltooid
```

## 3. State Diagram - Notificatie Verwerking

```mermaid
stateDiagram-v2
    [*] --> NotificatieOntvangen
    NotificatieOntvangen --> CheckZaaknummer
    
    CheckZaaknummer --> TimerLoopt: Timer bestaat al
    CheckZaaknummer --> StartTimer: Nieuwe zaak
    
    TimerLoopt --> HerstartTimer
    StartTimer --> WachtOpTimer
    HerstartTimer --> WachtOpTimer
    
    WachtOpTimer --> TimerAfgelopen
    TimerAfgelopen --> AnalyseerBatch
    
    AnalyseerBatch --> BevatZaakAangemaakt: Ja
    AnalyseerBatch --> GeenZaakAangemaakt: Nee
    
    BevatZaakAangemaakt --> SequentieelParallel: Eerst zaak aangemaakt,<br/>dan parallel
    GeenZaakAangemaakt --> AllesParallel: Alles parallel
    
    SequentieelParallel --> MaakJobChain
    AllesParallel --> MaakJobChain
    
    MaakJobChain --> VerwerkingVoltooid
    VerwerkingVoltooid --> [*]
```

## 4. Flowchart - Batch Verwerking Logica

```mermaid
flowchart TD
    Start([Notificatie ontvangen]) --> CheckZaak{Zaak bestaat<br/>in timer service?}
    
    CheckZaak -->|Ja| HerstartTimer[Herstart timer<br/>voor zaak]
    CheckZaak -->|Nee| StartTimer[Start nieuwe timer<br/>voor zaak]
    
    HerstartTimer --> Opslaan[Sla notificatie op<br/>in batch]
    StartTimer --> Opslaan
    
    Opslaan --> Wachten[Wacht op<br/>meer notificaties]
    
    Wachten --> TimerAf{Timer<br/>afgelopen?}
    TimerAf -->|Nee| NieuweNotif{Nieuwe notificatie<br/>ontvangen?}
    NieuweNotif -->|Ja| HerstartTimer
    NieuweNotif -->|Nee| Wachten
    
    TimerAf -->|Ja| AnalyseerBatch[Analyseer batch]
    
    AnalyseerBatch --> CheckType{Bevat<br/>'Zaak aangemaakt'?}
    
    CheckType -->|Ja| Sequentieel[Sequentiële verwerking]
    CheckType -->|Nee| Parallel[Parallelle verwerking]
    
    Sequentieel --> VerwerkZaak[1. Verwerk<br/>'Zaak aangemaakt']
    VerwerkZaak --> JobChain1[Create Job]
    JobChain1 --> Wait[Wacht op voltooiing]
    Wait --> VerwerkRest[2. Verwerk overige<br/>notificaties parallel]
    VerwerkRest --> JobChain2[Create parallel jobs:<br/>- Zaakstatus<br/>- Rol<br/>- Document]
    
    Parallel --> JobChain3[Create parallel jobs:<br/>- Zaakstatus<br/>- Rol<br/>- Document]
    
    JobChain2 --> Voltooid([Batch voltooid])
    JobChain3 --> Voltooid
```

## 5. Flowchart - Timer Mechanisme

```mermaid
flowchart TD
    Start([Timer Service]) --> Monitor{Monitor<br/>actieve timers}
    
    Monitor --> CheckExpired{Timer<br/>verlopen?}
    
    CheckExpired -->|Nee| Monitor
    CheckExpired -->|Ja| TriggerBatch[Trigger batch<br/>verwerking]
    
    TriggerBatch --> RemoveTimer[Verwijder timer<br/>voor zaak]
    RemoveTimer --> Monitor
    
    NewNotif([Nieuwe notificatie]) --> CheckTimer{Timer bestaat<br/>voor zaak?}
    
    CheckTimer -->|Ja| ResetTimer[Reset timer<br/>naar nu + interval]
    CheckTimer -->|Nee| CreateTimer[Maak nieuwe timer<br/>voor zaak]
    
    ResetTimer --> AddToBatch[Voeg notificatie toe<br/>aan batch]
    CreateTimer --> AddToBatch
    
    AddToBatch --> Monitor
```

## 6. Class Diagram - Data Model

```mermaid
classDiagram
    class NotificationBatch {
        +String zaak_id
        +DateTime created_at
        +DateTime timer_started_at
        +DateTime timer_expires_at
        +Enum status
        +Array notifications
        +startTimer()
        +restartTimer()
        +isExpired()
        +process()
    }
    
    class Notification {
        +Int id
        +Int batch_id
        +Enum type
        +JSON payload
        +DateTime received_at
        +Boolean processed
        +Int processing_order
        +getType()
        +markProcessed()
    }
    
    class NotificationType {
        <<enumeration>>
        ZAAK_AANGEMAAKT
        ZAAKSTATUS_TOEGEVOEGD
        ROL_TOEGEVOEGD
        DOCUMENT_TOEGEVOEGD
    }
    
    class BatchStatus {
        <<enumeration>>
        PENDING
        PROCESSING
        COMPLETED
        FAILED
    }
    
    class JobChain {
        +Int id
        +Int batch_id
        +Array jobs
        +Enum status
        +createSequentialChain()
        +createParallelChain()
        +execute()
    }
    
    class Job {
        +Int id
        +Int chain_id
        +String type
        +JSON data
        +Int priority
        +Boolean requires_completion
        +execute()
    }
    
    NotificationBatch "1" --> "*" Notification : contains
    Notification --> NotificationType : has type
    NotificationBatch --> BatchStatus : has status
    NotificationBatch "1" --> "1" JobChain : creates
    JobChain "1" --> "*" Job : contains
```

## 7. Architecture Diagram - Component Overview

```mermaid
graph TB
    subgraph External["Externe Systemen"]
        OZ[Open Zaak]
    end
    
    subgraph NotificationLayer["Notificatie Laag"]
        API[Notificatie API Endpoint]
        NH[Notificatie Handler]
        Validator[Payload Validator]
    end
    
    subgraph TimerLayer["Timer Laag"]
        TS[Timer Service]
        TC[Timer Controller]
        Cache[(Redis Cache)]
    end
    
    subgraph BatchLayer["Batch Verwerking"]
        BP[Batch Processor]
        BS[Batch Sorter]
        BV[Batch Validator]
    end
    
    subgraph JobLayer["Job Verwerking (Laravel)"]
        JC[Job Chain Builder]
        Queue[(Job Queue)]
        Workers[Queue Workers]
    end
    
    subgraph JobTypes["Job Types"]
        J1[Zaak Aangemaakt Job]
        J2[Zaakstatus Job]
        J3[Rol Job]
        J4[Document Job]
    end
    
    subgraph Database["Database"]
        DB[(PostgreSQL/MySQL)]
    end
    
    OZ -->|POST notification| API
    API --> Validator
    Validator --> NH
    NH --> TS
    NH --> DB
    
    TS --> TC
    TC --> Cache
    Cache -.->|Timer expired| BP
    
    BP --> BS
    BS --> BV
    BV --> JC
    
    JC --> Queue
    Queue --> Workers
    
    Workers --> J1
    Workers --> J2
    Workers --> J3
    Workers --> J4
    
    J1 --> DB
    J2 --> DB
    J3 --> DB
    J4 --> DB
```

## 8. Timing Diagram - Timer Reset Mechanisme

```mermaid
gantt
    title Timer Reset bij Notificaties (Zaak 123)
    dateFormat ss
    axisFormat %S sec
    
    section Notificaties
    Notificatie 1 ontvangen    :milestone, n1, 00, 0s
    Notificatie 2 ontvangen    :milestone, n2, 03, 0s
    Notificatie 3 ontvangen    :milestone, n3, 07, 0s
    
    section Timer
    Timer 1 (5 sec)            :t1, 00, 5s
    Timer 2 (5 sec)            :t2, 03, 5s
    Timer 3 (5 sec)            :t3, 07, 5s
    
    section Verwerking
    Batch verwerking start     :milestone, process, 12, 0s
    Batch verwerking           :active, 12, 3s
```

## 9. Decision Tree - Verwerkingsvolgorde

```mermaid
graph TD
    Start{Batch notificaties<br/>ontvangen}
    
    Start --> Analyse[Analyseer notificatie types<br/>in batch]
    
    Analyse --> Check{Bevat batch<br/>'Zaak aangemaakt'?}
    
    Check -->|Ja| Priority[Prioriteit verwerking]
    Check -->|Nee| AllParallel[Alle notificaties<br/>parallel verwerken]
    
    Priority --> Step1[STAP 1: Sequentieel]
    Step1 --> ZaakJob[Verwerk 'Zaak aangemaakt']
    ZaakJob --> Wait{Voltooid?}
    Wait -->|Nee| Wait
    Wait -->|Ja| Step2[STAP 2: Parallel]
    
    Step2 --> ParallelGroup[Verwerk parallel:]
    
    ParallelGroup --> StatusJob[Zaakstatus jobs]
    ParallelGroup --> RolJob[Rol jobs]
    ParallelGroup --> DocJob[Document jobs]
    
    AllParallel --> AllStatusJob[Zaakstatus jobs]
    AllParallel --> AllRolJob[Rol jobs]
    AllParallel --> AllDocJob[Document jobs]
    
    StatusJob --> Done([Klaar])
    RolJob --> Done
    DocJob --> Done
    
    AllStatusJob --> Done
    AllRolJob --> Done
    AllDocJob --> Done
```

## 10. Swimlane Diagram - Verantwoordelijkheden

```mermaid
graph TB
    subgraph OpenZaak["Open Zaak Systeem"]
        OZ1[Genereer notificatie]
        OZ2[Verstuur webhook]
    end
    
    subgraph CorsaAPI["Corsa - API Layer"]
        API1[Ontvang notificatie]
        API2[Valideer payload]
        API3[Return 200 OK]
    end
    
    subgraph CorsaTimer["Corsa - Timer Service"]
        T1[Check zaak timer]
        T2[Start/Reset timer]
        T3[Monitor timers]
        T4[Trigger batch verwerking]
    end
    
    subgraph CorsaBatch["Corsa - Batch Processor"]
        B1[Verzamel notificaties]
        B2[Sorteer op type]
        B3[Bepaal volgorde]
        B4[Maak job chain]
    end
    
    subgraph CorsaJobs["Corsa - Job Processor"]
        J1[Voer jobs uit]
        J2[Update status]
        J3[Log resultaten]
    end
    
    OZ1 --> OZ2
    OZ2 --> API1
    API1 --> API2
    API2 --> T1
    API2 --> API3
    T1 --> T2
    T2 --> T3
    T3 --> T4
    T4 --> B1
    B1 --> B2
    B2 --> B3
    B3 --> B4
    B4 --> J1
    J1 --> J2
    J2 --> J3
```

Deze Mermaid diagrammen kunnen direct in Markdown documenten, GitLab, GitHub, Notion en andere tools worden gebruikt die Mermaid ondersteunen!
User: Voeg hier een keuze in toe: Als een zaak aangemaakt notificatie bestaat, verwerk zoals hier beschreven, als er geen zaak gemaakt notificatie is, controleer in corsa of de zaak bestaat, en verwerk alleen als dat zo is:
```mermaid
sequenceDiagram
    participant OZ as Open Zaak
    participant NH as Notificatie Handler
    participant TS as Timer Service
    participant BP as Batch Processor
    participant LC as Corsa (Laravel)

    OZ->>NH: Notificatie 1 (Zaak ID: 123)
    NH->>NH: Check zaaknummer
    NH->>TS: Start timer (Zaak 123)
    Note over TS: Timer loopt...
    
    OZ->>NH: Notificatie 2 (Zaak ID: 123)
    NH->>NH: Check zaaknummer (123)
    NH->>TS: Herstart timer (Zaak 123)
    Note over TS: Timer herstart...
    
    OZ->>NH: Notificatie 3 (Zaak ID: 123)
    NH->>NH: Check zaaknummer (123)
    NH->>TS: Herstart timer (Zaak 123)
    Note over TS: Timer loopt...
    
    TS->>NH: Timer afgelopen (Zaak 123)
    NH->>BP: Verwerk batch (Zaak 123)
    
    BP->>BP: Analyseer batch en sorteer notificaties
    BP->>BP: Bevat "Zaak aangemaakt"? Ja
    
    BP->>LC: Verwerk eerst: "Zaak aangemaakt"
    LC->>LC: Create Job Chain<br/>(Zaak aangemaakt)
    LC->>BP: Job voltooid


    BP->>BP: Analyseer batch en sorteer notificaties
    BP->>BP: Bevat "Zaak aangemaakt"? Nee

    BP->>LC: Bestaat zaak al?
    LC->>BP: Zaak

    BP->>LC: Verwerk parallel:<br/>- Zaakstatus<br/>- Rol<br/>- Document
    
    par Parallel verwerking
        LC->>LC: Job: Zaakstatus
    and
        LC->>LC: Job: Rol
    and
        LC->>LC: Job: Document
    end
    
    LC->>BP: Jobs voltooid
    BP->>NH: Batch voltooid
```
Model: Hier is het aangepaste sequence diagram met de toegevoegde logica:

```mermaid
sequenceDiagram
    participant OZ as Open Zaak
    participant NH as Notificatie Handler
    participant TS as Timer Service
    participant BP as Batch Processor
    participant LC as Corsa (Laravel)
    participant DB as Corsa Database

    OZ->>NH: Notificatie 1 (Zaak ID: 123)
    NH->>NH: Check zaaknummer
    NH->>TS: Start timer (Zaak 123)
    Note over TS: Timer loopt...
    
    OZ->>NH: Notificatie 2 (Zaak ID: 123)
    NH->>NH: Check zaaknummer (123)
    NH->>TS: Herstart timer (Zaak 123)
    Note over TS: Timer herstart...
    
    OZ->>NH: Notificatie 3 (Zaak ID: 123)
    NH->>NH: Check zaaknummer (123)
    NH->>TS: Herstart timer (Zaak 123)
    Note over TS: Timer loopt...
    
    TS->>NH: Timer afgelopen (Zaak 123)
    NH->>BP: Verwerk batch (Zaak 123)
    
    BP->>BP: Analyseer batch en sorteer notificaties
    
    alt Bevat "Zaak aangemaakt"
        Note over BP: Scenario A: Zaak aangemaakt aanwezig
        BP->>LC: Verwerk eerst: "Zaak aangemaakt"
        LC->>LC: Create Job Chain<br/>(Zaak aangemaakt)
        LC->>DB: Sla zaak op
        DB->>LC: Zaak opgeslagen
        LC->>BP: Job voltooid
        
        BP->>LC: Verwerk parallel:<br/>- Zaakstatus<br/>- Rol<br/>- Document
        
        par Parallel verwerking
            LC->>LC: Job: Zaakstatus
        and
            LC->>LC: Job: Rol
        and
            LC->>LC: Job: Document
        end
        
        LC->>BP: Jobs voltooid
        
    else Geen "Zaak aangemaakt"
        Note over BP: Scenario B: Controleer of zaak bestaat
        BP->>LC: Controleer: Bestaat zaak al?
        LC->>DB: SELECT zaak WHERE zaak_id = 123
        
        alt Zaak bestaat
            DB->>LC: Zaak gevonden
            LC->>BP: Ja, zaak bestaat
            
            Note over BP,LC: Zaak bestaat - verwerk notificaties
            BP->>LC: Verwerk parallel:<br/>- Zaakstatus<br/>- Rol<br/>- Document
            
            par Parallel verwerking
                LC->>LC: Job: Zaakstatus
            and
                LC->>LC: Job: Rol
            and
                LC->>LC: Job: Document
            end
            
            LC->>BP: Jobs voltooid
            
        else Zaak bestaat niet
            DB->>LC: Zaak niet gevonden
            LC->>BP: Nee, zaak bestaat niet
            
            Note over BP: Notificaties negeren - zaak onbekend
            BP->>BP: Log waarschuwing:<br/>Notificaties voor onbekende zaak
            BP->>BP: Markeer batch als genegeerd
        end
    end
    
    BP->>NH: Batch voltooid
```

## Bijbehorende Flowchart met de nieuwe logica

```mermaid
flowchart TD
    Start([Timer afgelopen]) --> AnalyseerBatch[Analyseer batch]
    
    AnalyseerBatch --> CheckAangemaakt{Bevat batch<br/>'Zaak aangemaakt'?}
    
    CheckAangemaakt -->|Ja| ScenarioA[Scenario A:<br/>Zaak aangemaakt aanwezig]
    CheckAangemaakt -->|Nee| ScenarioB[Scenario B:<br/>Controleer bestaande zaak]
    
    ScenarioA --> VerwerkZaak[1. Verwerk<br/>'Zaak aangemaakt']
    VerwerkZaak --> SlaOp[Sla zaak op in Corsa]
    SlaOp --> JobChain1[Create Job]
    JobChain1 --> Wait[Wacht op voltooiing]
    Wait --> VerwerkRestA[2. Verwerk overige<br/>notificaties parallel]
    VerwerkRestA --> JobChainParallelA[Create parallel jobs]
    
    ScenarioB --> CheckExists{Bestaat zaak<br/>in Corsa?}
    
    CheckExists -->|Ja| ZaakBestaat[Zaak gevonden]
    CheckExists -->|Nee| ZaakNiet[Zaak niet gevonden]
    
    ZaakBestaat --> VerwerkParallel[Verwerk notificaties<br/>parallel]
    VerwerkParallel --> JobChainParallelB[Create parallel jobs:<br/>- Zaakstatus<br/>- Rol<br/>- Document]
    
    ZaakNiet --> LogWaarschuwing[Log waarschuwing:<br/>Onbekende zaak]
    LogWaarschuwing --> NegeerId[Markeer batch<br/>als genegeerd]
    NegeerId --> EindNegeer([Batch genegeerd])
    
    JobChainParallelA --> Voltooid([Batch voltooid])
    JobChainParallelB --> Voltooid
```

## State Diagram met nieuwe logica

```mermaid
stateDiagram-v2
    [*] --> TimerAfgelopen
    TimerAfgelopen --> AnalyseerBatch
    
    AnalyseerBatch --> CheckZaakAangemaakt
    
    state CheckZaakAangemaakt <<choice>>
    CheckZaakAangemaakt --> VerwerkMetZaakAangemaakt: Zaak aangemaakt aanwezig
    CheckZaakAangemaakt --> ControleerBestaandeZaak: Geen zaak aangemaakt
    
    VerwerkMetZaakAangemaakt --> CreateZaak
    CreateZaak --> VerwerkOverige
    VerwerkOverige --> MaakJobChain
    
    ControleerBestaandeZaak --> CheckDatabase
    
    state CheckDatabase <<choice>>
    CheckDatabase --> ZaakBestaat: Zaak gevonden
    CheckDatabase --> ZaakBestaatNiet: Zaak niet gevonden
    
    ZaakBestaat --> VerwerkAllesParallel
    VerwerkAllesParallel --> MaakJobChain
    
    ZaakBestaatNiet --> LogWaarschuwing
    LogWaarschuwing --> NegeerBatch
    NegeerBatch --> [*]
    
    MaakJobChain --> VerwerkingVoltooid
    VerwerkingVoltooid --> [*]
```

## Decision Tree - Uitgebreide verwerkingslogica

```mermaid
graph TD
    Start{Timer afgelopen<br/>Batch notificaties}
    
    Start --> Analyse[Analyseer notificatie types<br/>in batch]
    
    Analyse --> Check{Bevat batch<br/>'Zaak aangemaakt'?}
    
    Check -->|Ja| ScenarioA[SCENARIO A]
    Check -->|Nee| ScenarioB[SCENARIO B]
    
    ScenarioA --> Priority[Prioriteit verwerking]
    Priority --> Step1[STAP 1: Sequentieel]
    Step1 --> ZaakJob[Verwerk 'Zaak aangemaakt'<br/>+ sla op in database]
    ZaakJob --> WaitA{Voltooid?}
    WaitA -->|Nee| WaitA
    WaitA -->|Ja| Step2[STAP 2: Parallel]
    
    Step2 --> ParallelGroup[Verwerk parallel:]
    ParallelGroup --> StatusJobA[Zaakstatus jobs]
    ParallelGroup --> RolJobA[Rol jobs]
    ParallelGroup --> DocJobA[Document jobs]
    
    ScenarioB --> CheckDB{Controleer:<br/>Bestaat zaak<br/>in Corsa?}
    
    CheckDB -->|Ja| ZaakGevonden[Zaak gevonden<br/>in database]
    CheckDB -->|Nee| ZaakNietGevonden[Zaak NIET gevonden<br/>in database]
    
    ZaakGevonden --> AllParallel[Verwerk notificaties<br/>parallel]
    AllParallel --> AllStatusJob[Zaakstatus jobs]
    AllParallel --> AllRolJob[Rol jobs]
    AllParallel --> AllDocJob[Document jobs]
    
    ZaakNietGevonden --> LogError[Log waarschuwing:<br/>'Notificaties voor<br/>onbekende zaak']
    LogError --> Ignore[Negeer alle notificaties<br/>in batch]
    Ignore --> MarkIgnored[Markeer batch<br/>als 'ignored']
    MarkIgnored --> EndIgnored([Batch genegeerd])
    
    StatusJobA --> Done([Batch voltooid])
    RolJobA --> Done
    DocJobA --> Done
    
    AllStatusJob --> Done
    AllRolJob --> Done
    AllDocJob --> Done
    
    style ZaakNietGevonden fill:#ffcccc
    style LogError fill:#ffcccc
    style Ignore fill:#ffcccc
    style EndIgnored fill:#ff9999
```

## Uitgebreide Flowchart met alle scenario's

```mermaid
flowchart TD
    Start([Batch notificaties<br/>klaar voor verwerking])
    
    Start --> GetBatch[Haal batch op<br/>uit timer service]
    GetBatch --> SortNotif[Sorteer notificaties<br/>op type en timestamp]
    
    SortNotif --> CheckType{Bevat<br/>'Zaak aangemaakt'?}
    
    CheckType -->|Ja| PathA[PATH A:<br/>Met zaak aangemaakt]
    CheckType -->|Nee| PathB[PATH B:<br/>Zonder zaak aangemaakt]
    
    PathA --> CreateZaakJob[1. Create job:<br/>Zaak aangemaakt]
    CreateZaakJob --> ExecuteZaak[Execute job]
    ExecuteZaak --> SaveZaak[Sla zaak op<br/>in Corsa database]
    SaveZaak --> SuccessA{Success?}
    
    SuccessA -->|Ja| CreateParallelA[2. Create parallel jobs<br/>voor overige notificaties]
    SuccessA -->|Nee| ErrorHandlingA[Error handling<br/>Log fout]
    ErrorHandlingA --> MarkFailedA[Markeer batch<br/>als failed]
    MarkFailedA --> EndFailedA([Batch failed])
    
    CreateParallelA --> ExecuteParallelA[Execute parallel jobs]
    ExecuteParallelA --> EndSuccessA([Batch voltooid])
    
    PathB --> QueryDB[Query Corsa database:<br/>Bestaat zaak?]
    QueryDB --> DBResult{Zaak<br/>gevonden?}
    
    DBResult -->|Ja| ZaakExists[Zaak bestaat in Corsa]
    DBResult -->|Nee| ZaakNotExists[Zaak bestaat NIET]
    
    ZaakExists --> CreateParallelB[Create parallel jobs<br/>voor alle notificaties]
    CreateParallelB --> ExecuteParallelB[Execute parallel jobs]
    ExecuteParallelB --> EndSuccessB([Batch voltooid])
    
    ZaakNotExists --> LogWarning[Log waarschuwing:<br/>'Notificaties ontvangen<br/>voor onbekende zaak']
    LogWarning --> StoreForLater{Bewaar voor<br/>later?}
    
    StoreForLater -->|Ja| QueueRetry[Plaats in<br/>retry queue]
    StoreForLater -->|Nee| DiscardBatch[Verwijder batch]
    
    QueueRetry --> MarkPending[Markeer als<br/>'pending_zaak']
    DiscardBatch --> MarkIgnored[Markeer als<br/>'ignored']
    
    MarkPending --> EndPending([Wacht op zaak])
    MarkIgnored --> EndIgnored([Batch genegeerd])
    
    style ZaakNotExists fill:#ffcccc
    style LogWarning fill:#ffe6cc
    style DiscardBatch fill:#ffcccc
    style EndIgnored fill:#ff9999
    style MarkFailedA fill:#ffcccc
    style EndFailedA fill:#ff9999
```

## Class Diagram met uitgebreide logica

```mermaid
classDiagram
    class BatchProcessor {
        +NotificationBatch batch
        +processBatch()
        -containsZaakAangemaakt() bool
        -checkZaakExists(zaakId) bool
        -createJobChain()
        -handleUnknownZaak()
    }
    
    class NotificationBatch {
        +String zaak_id
        +Array notifications
        +Enum status
        +containsType(type) bool
        +getNotificationsByType(type)
        +markAsIgnored()
        +markAsFailed()
    }
    
    class ZaakRepository {
        +findByZaakId(zaakId) Zaak
        +exists(zaakId) bool
        +create(zaakData) Zaak
    }
    
    class JobChainBuilder {
        +buildSequentialChain(batch)
        +buildParallelChain(batch)
        +buildWithZaakCheck(batch)
    }
    
    class ProcessingStrategy {
        <<interface>>
        +process(batch)
        +validate(batch)
    }
    
    class WithZaakAangemaaktStrategy {
        +process(batch)
        +validate(batch)
        -createZaakFirst()
        -processRemaining()
    }
    
    class WithoutZaakAangemaaktStrategy {
        +process(batch)
        +validate(batch)
        -checkZaakExists()
        -processOrIgnore()
    }
    
    class BatchStatus {
        <<enumeration>>
        PENDING
        PROCESSING
        COMPLETED
        IGNORED
        FAILED
        PENDING_ZAAK
    }
    
    BatchProcessor --> NotificationBatch
    BatchProcessor --> ZaakRepository
    BatchProcessor --> JobChainBuilder
    BatchProcessor --> ProcessingStrategy
    
    ProcessingStrategy <|-- WithZaakAangemaaktStrategy
    ProcessingStrategy <|-- WithoutZaakAangemaaktStrategy
    
    WithZaakAangemaaktStrategy --> ZaakRepository
    WithoutZaakAangemaaktStrategy --> ZaakRepository
    
    NotificationBatch --> BatchStatus
```

Deze diagrammen tonen nu duidelijk de keuzelogica waarbij:
1. **Met "Zaak aangemaakt"**: Direct verwerken zoals eerder beschreven
2. **Zonder "Zaak aangemaakt"**: Eerst controleren of de zaak bestaat in Corsa, en alleen verwerken als dat zo is, anders negeren of bewaren voor later
