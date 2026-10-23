# Mislukte notificaties opnieuw verwerken

Deze handleiding beschrijft hoe je een notificatie waarvan de verwerking naar Corsa is mislukt, opnieuw laat verwerken. Voor de achtergrond zie [batching_system.md](batching_system.md) en, in [notificaties.md](notificaties.md), de secties "Mislukte notificaties" en "Directe route voor documenten in bewerking".

## Hoe opnieuw proberen werkt

Een notificatie wordt verwerkt via een van twee routes. Opnieuw proberen werkt per route anders.

### Batchroute: een keten van jobs

Als de timer van een batch afloopt, maakt `DispatchBatch` een `Bus::chain` met één `HandleNotification`-job per notificatie, in de volgorde van de kolom **Volgorde** op de batchpagina:

| Volgorde | Notificaties |
|---|---|
| 0 | `create:zaak` |
| 1 | `create:status`, `create:zaakinformatieobject` (in volgorde van binnenkomst) |
| 2 | documentwijzigingen (`update`/`partial_update:enkelvoudiginformatieobject`) |
| 3 | `create:resultaat` |

De keten eindigt met `MarkBatchProcessed`, die de batch op `processed` zet.

De jobs draaien **na elkaar**, nooit parallel. Elke job in de queue bevat de lijst van jobs die er nog na komen. Slaagt een job, dan zet hij de volgende uit die lijst op de queue.

Een `HandleNotification`-job mislukt bij de eerste fout; hij wordt niet automatisch opnieuw geprobeerd. Wachten op een document dat door een andere job wordt verstuurd of nog wordt geüpload, telt niet als fout.

Als een job mislukt:

- stopt de keten: de notificaties erna blijven **Nog niet verwerkt** en de batch blijft op `processing`;
- krijgt de notificatie de status **Mislukt**, met de foutomschrijving en een link naar de mislukte job in Horizon;
- bewaart Horizon de mislukte job, inclusief de lijst van jobs die er nog na kwamen.

Start je die mislukte job opnieuw, dan:

- draait de mislukte notificatie opnieuw;
- worden bij succes de notificaties erna weer één voor één verwerkt, en zet `MarkBatchProcessed` de batch daarna op `processed`;
- worden de notificaties die vóór de fout al gelukt waren **niet** opnieuw uitgevoerd, omdat ze niet meer in de opgeslagen keten staan.

Een batch heeft dus hooguit één mislukte job tegelijk. Die job opnieuw starten hervat de batch vanaf het punt waar het misging.

> **Let op:** gelukte notificaties worden niet overgeslagen doordat er een controle op "Verwerkt" plaatsvindt. Ze worden alleen niet uitgevoerd omdat ze niet in de opgeslagen keten staan. Start dezelfde mislukte job daarom maar één keer. Voor documenten is een tweede keer onschadelijk: elk verstuurd document staat in het register `document_syncs` en wordt geen tweede keer toegevoegd. Voor zaken controleert de verwerking eerst of de zaak al in Corsa staat. Een status of resultaat wordt wel opnieuw naar Corsa gestuurd.

### Directe route: losse jobs

Documenten die nog `in_bewerking` zijn en alle documentwijzigingen gaan via de directe route: één `ProcessDocumentDirectly`-job per notificatie op de queue `direct`, zonder keten. Deze notificaties staan niet in een batch, alleen in het overzicht **Notificaties** met route **Direct**.

Bij een tijdelijke fout (verbindingsfout, time-out, 429 of serverfout) probeert de job het zelf opnieuw na 30 seconden, 2 minuten en 5 minuten. Pas daarna, of meteen bij een andere fout, krijgt de notificatie de status **Mislukt**. Opnieuw starten raakt alleen die ene notificatie.

Een directe job slaat zichzelf over als de notificatie al verwerkt is of inmiddels naar de batch van de zaak is verplaatst. Een tweede keer opnieuw starten is op deze route dus onschadelijk.

## Herkennen dat er iets mislukt is

In het admin-paneel, onder **Monitoring**:

- **Notificatie-batches**: de kolom **Mislukt** (rood) toont per batch het aantal mislukte notificaties. Zet het filter **Met mislukte notificaties** aan om alleen die batches te zien.
- **Notificaties**: filter op **Status → Mislukt**. Dit overzicht bevat ook de notificaties van de directe route.

Per notificatie is de status een van:

| Status | Betekenis |
|---|---|
| **Verwerkt** | Naar Corsa gestuurd. |
| **Mislukt** | De job is definitief mislukt. Beweeg over de status voor de foutomschrijving. |
| **Nog niet verwerkt** | Nog in de wachtrij, of tegengehouden door een mislukte notificatie eerder in dezelfde batch. |
| **Genegeerd** | Bewust niet verwerkt (zie **Genegeerde notificaties**). Opnieuw verwerken kan hier niet. |

Een batch op `processing` zonder mislukte notificatie is nog bezig. Controleer in Horizon onder **Pending jobs** (zoek op `zaak:<zaaknummer>`) of er nog jobs klaarstaan.

## Een mislukte notificatie opnieuw verwerken

Dit is de standaardmanier, voor beide routes.

1. Zoek de notificatie op zoals hierboven. Gebruik bij een batch de bovenste notificatie met status **Mislukt** in de kolom **Volgorde**.
2. Lees de foutomschrijving (tooltip bij de status, of **Bekijk payload**). Bepaal of de oorzaak is opgelost; zie [Wanneer niet opnieuw starten](#wanneer-niet-opnieuw-starten).
3. Klik bij de notificatie op het rode icoon **Mislukte job**. De pagina van de job in Horizon opent in een nieuw tabblad.
4. Controleer onder **Exception** de volledige foutmelding en klik op **Retry**.
5. Ga terug naar het admin-paneel. De overzichten verversen elke 10 seconden. Controleer dat:
   - de notificatie op **Verwerkt** staat;
   - bij een batch: de notificaties erna ook op **Verwerkt** staan en de batch op `processed`.

Mislukt de job opnieuw, dan staat de notificatie weer op **Mislukt** en wijst **Mislukte job** naar de **nieuwe** mislukte job. Start vanaf dat moment alleen die opnieuw, niet de oude in Horizon.

Ontbreekt het icoon **Mislukte job**, dan is het id van de job niet bekend of heeft Horizon de job al opgeruimd (na 7 dagen). Zoek de job dan in Horizon onder **Failed Jobs** op de tag `zaak:<zaaknummer>`, of gebruik de command line.

### Meerdere batches van dezelfde zaak

Zolang een batch met een mislukte notificatie op `processing` blijft, komen nieuwe notificaties voor die zaak in een nieuwe batch. Die nieuwe batch wordt gewoon verwerkt en kan daardoor ook mislukken, bijvoorbeeld met `Missing zaak in Corsa` als de `create:zaak` in de oude batch mislukte.

Verwerk daarom altijd eerst de **oudste** batch van een zaak opnieuw, en daarna pas de mislukte notificaties in latere batches.

## Opnieuw verwerken via de command line

Gebruik dit als Horizon de job al heeft opgeruimd of niet bereikbaar is. De tabel `failed_jobs` bewaart mislukte jobs ook na die 7 dagen.

Mislukte jobs tonen:

```bash
php artisan queue:failed
```

Eén job opnieuw starten, met de UUID uit het overzicht. Dat is hetzelfde id als in de link **Mislukte job** (`.../failed/<uuid>`):

```bash
php artisan queue:retry <uuid>
```

Lokaal met Sail gebruik je `./vendor/bin/sail artisan` in plaats van `php artisan`.

> **Let op:** een retry in Horizon haalt de job niet uit `failed_jobs`. Een job die je al in Horizon opnieuw hebt gestart, staat dus nog steeds in `queue:failed`. Start hem daar niet nog een keer, en gebruik `queue:retry all` alleen als je zeker weet dat geen van die jobs al via Horizon opnieuw is gestart.

## Wanneer niet opnieuw starten

Opnieuw starten helpt bij tijdelijke fouten, zoals een time-out of een storing bij Open Zaak of Corsa. Bij de volgende fouten mislukt de job opnieuw zolang de oorzaak niet is opgelost:

| Foutomschrijving | Oorzaak | Wat te doen |
|---|---|---|
| `DisallowedHostException` | De notificatie verwijst naar een URL buiten de geconfigureerde ZGW-hosts. | Niet opnieuw starten. Controleer de configuratie of de bron van de notificatie. |
| `Missing zaak in Corsa for ...` | De zaak staat niet in Corsa, meestal omdat `create:zaak` in een eerdere batch mislukte. | Verwerk eerst die `create:zaak` opnieuw (zie [Meerdere batches](#meerdere-batches-van-dezelfde-zaak)), daarna deze notificatie. |
| `Missing document in Corsa for document update` | Een documentwijziging in een batch, terwijl het document niet in Corsa staat. | Controleer of de koppeling van het document (`create:zaakinformatieobject`) is verwerkt, eventueel in een andere batch. Start daarna opnieuw. |
| `Document upload is not complete` (directe route) of `MaxAttemptsExceededException` (batchroute) | De inhoud van het document werd na alle wachttijden nog steeds geüpload. | Opnieuw starten zodra de upload in Open Zaak klaar is. |
| `Missing ... url for ...` | De notificatie mist een verplicht veld. | Niet opnieuw starten; de opgeslagen notificatie verandert niet. Bekijk de payload via **Bekijk payload**. |
| `HTTP 4xx` (behalve 408 en 429) | Open Zaak of Corsa weigert het verzoek. | Eerst de oorzaak in de logs of Telescope zoeken, daarna opnieuw starten. |
| Mapping-fouten (bijv. `Undefined array key`, `TypeError`) | De zaakgegevens passen niet in de mapping naar Corsa. | Eerst de code of de zaakgegevens aanpassen, daarna opnieuw starten. |

Een batch met het signaal **Niet alles verwerkt** (oranje) heeft geen mislukte job om opnieuw te starten. Dat hoort niet voor te komen; meld het bij de ontwikkelaars.

## Controle na opnieuw starten

- De notificatie, en bij een batch ook alle notificaties erna, staan op **Verwerkt**.
- De batch staat op `processed` en de kolom **Mislukt** is leeg.
- In Horizon staat er voor de zaak geen nieuwe mislukte job.
- Bij een opnieuw gestart document: het document staat één keer in Corsa. Het register voorkomt dubbele documenten. Alleen als Corsa het document opsloeg terwijl de eerdere poging een time-out gaf, kan het toch dubbel staan.
