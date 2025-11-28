# Verwerking notificaties open zaak naar Corsa

## Volgordelijkheid van berichten
Omdat notificaties in onvoorspelbare volgorde binnenkomen, én verwerking tijd kost, moeten notificaties (soms) in een specifieke volgorde worden verwerkt.
Daarnaast is het zo dat onvoorspelbaar is welke notificaties binnenkomen en binnen moeten zijn voor een taak gestart kan worden. Daarom verwerken we
notificaties als volgt:
- Bij elke notificatie die binnenkomt checken we het zaaknummer
- Voor elk zaaknummer starten we bij een binnengekomen notificatie een timer
- Als een nieuwe notificatie binnenkomt voor die zaak terwijl de timer loopt herstarten we de timer
- Als de timer afloopt starten we verwerking van de notificaties, op een logische volgorde

Voor het bepalen van de volgorde hanteren we de volgende types notificaties die we willen verwerken:
- Zaak aangemaakt
- Zaakstatus aan zaak toegevoegd
- Rol aan zaak toegevoegd
- Document aan zaak toegevoegd

De 'zaak aangemaakt' notificatie moet altijd als eerste worden verwerkt. Andere notificaties kunnen daarna parallel worden verwerkt. Als in een 
notificatiebatch (gedefinieerd als de set notificaties die binnen een timer-periode binnen zijn) géén zaak aangemaakt zit kunnen de notificaties parallel
worden verwerkt, anders moet eerst de 'zaak aangemaakt'-notificatie worden afgehandeld.

## Afhandeling
Van elke batch wordt een 'job chain' gedefinieerd in de applicatie (Laravel). 

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

## Flowchart

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

## Batchingmechanisme met timer
```mermaid
gantt
    title Timer Reset bij Notificaties - Meerdere Zaken
    dateFormat ss
    axisFormat %S sec
    
    section Zaak 123 - Notificaties
    Notificatie 1 ontvangen    :milestone, n1_123, 00, 0s
    Notificatie 2 ontvangen    :milestone, n2_123, 03, 0s
    Notificatie 3 ontvangen    :milestone, n3_123, 07, 0s
    Notificatie 4 ontvangen    :milestone, n4_123, 17, 0s
    
    section Zaak 123 - Timer
    Timer Zaak 123 (5 sec)     :t1_123, 00, 5s
    Timer Zaak 123 reset       :t2_123, 03, 5s
    Timer Zaak 123 reset       :t3_123, 07, 5s
    Timer Zaak 123 (5 sec)       :t3_123, 17, 5s
    
    section Zaak 123 - Verwerking
    Batch verwerking start     :milestone, process_123, 12, 0s
    Batch verwerking Zaak 123  :active, p_123, 12, 6s
    Batch verwerking start     :milestone, process_123, 22, 0s
    Batch verwerking Zaak 123  :active, p_123, 22, 6s
    
    section Zaak 456 - Notificaties
    Notificatie 1 ontvangen    :milestone, n1_456, 13, 0s
    Notificatie 2 ontvangen    :milestone, n2_456, 15, 0s
    Notificatie 3 ontvangen    :milestone, n3_456, 17, 0s
    Notificatie 4 ontvangen    :milestone, n4_456, 20, 0s
    
    section Zaak 456 - Timer
    Timer Zaak 456 (5 sec)     :t1_456, 13, 5s
    Timer Zaak 456 reset       :t2_456, 15, 5s
    Timer Zaak 456 reset       :t3_456, 17, 5s
    Timer Zaak 456 reset       :t4_456, 20, 5s
    
    section Zaak 456 - Verwerking
    Batch verwerking start     :milestone, process_456, 25, 0s
    Batch verwerking Zaak 456  :active, p_456, 25, 3s
    
    section Zaak 789 - Notificaties
    Notificatie 1 ontvangen    :milestone, n1_789, 14, 0s
    
    section Zaak 789 - Timer
    Timer Zaak 789 (5 sec)     :t1_789, 14, 5s
    
    section Zaak 789 - Verwerking
    Batch verwerking start     :milestone, process_789, 19, 0s
    Batch verwerking Zaak 789  :active, p_789, 19, 2s
```
