# Mislukte notificaties opnieuw verwerken

Deze handleiding beschrijft hoe je een batch notificaties waarvan de verwerking naar Corsa is mislukt, opnieuw laat verwerken. Voor de achtergrond van het batchingsysteem zie [batching_system.md](batching_system.md) en [notificaties.md](notificaties.md).

## Hoe de jobketen werkt

Als de timer van een batch afloopt, maakt `DispatchBatch` een `Bus::chain` met één `HandleNotification`-job per notificatie, in vaste volgorde:

1. `create:zaak`
2. `create:status` en `create:zaakinformatieobject`, in volgorde van binnenkomst
3. `create:resultaat`

De jobs draaien **na elkaar**, nooit parallel. Elke job in de queue bevat de lijst van jobs die er nog na komen. Als een job slaagt, zet hij de volgende job uit die lijst op de queue.

Als een job faalt, stopt de keten. Horizon bewaart de mislukte job, inclusief de lijst van jobs die er nog na kwamen. Wordt die mislukte job opnieuw gestart, dan:

- draait de mislukte notificatie opnieuw;
- worden bij succes de resterende notificaties daarna weer één voor één verwerkt;
- worden de notificaties die vóór de fout al gelukt waren **niet** opnieuw uitgevoerd, omdat ze niet meer in de opgeslagen keten staan.

Een keten heeft dus hooguit één mislukte job tegelijk. Die job opnieuw starten hervat de batch vanaf het punt waar het misging.

> **Let op:** gelukte notificaties worden niet overgeslagen doordat er een controle op `processed` plaatsvindt. Ze worden alleen niet uitgevoerd omdat ze niet in de opgeslagen keten staan. Start je dezelfde mislukte job twee keer, of een job die toch gelukt was, dan wordt de notificatie opnieuw naar Corsa gestuurd. Bij documenten levert dat een dubbel document op.

## Herkennen dat een batch is mislukt

De status van een batch zegt niets over het resultaat. Een batch staat op `processed` zodra de keten **is aangemaakt**, ook als alle notificaties daarna mislukken.

Kijk daarom naar de notificaties zelf:

1. Open in het admin-paneel **Monitoring → Batches**.
2. Zoek op het zaaknummer en open de batch.
3. Onder de notificaties staat per regel de kolom **Verwerkt**. Notificaties zonder vinkje zijn niet (of nog niet) verwerkt.

De eerste niet-verwerkte notificatie in de kolom **Volgorde** is de notificatie die mislukte. De notificaties daarna zijn nooit gestart.

> Is de batch net verwerkt, wacht dan even: de keten kan nog bezig zijn. Controleer in Horizon onder **Pending jobs** of er nog jobs voor de zaak klaarstaan.

## Een mislukte batch opnieuw verwerken via Horizon

Dit is de standaardmanier.

1. Open in het admin-paneel **Monitoring → Job monitor**. Horizon opent in een nieuw tabblad.
2. Ga naar **Failed Jobs**.
3. Zoek op de tag van de zaak: `zaak:<zaaknummer>`, bijvoorbeeld `zaak:ZAAK-2026-0001234`.
4. Open de mislukte job (`Handle create:...`) en lees de foutmelding onder **Exception**. Bepaal of de oorzaak is opgelost; zie [Wanneer niet opnieuw starten](#wanneer-niet-opnieuw-starten).
5. Klik op **Retry**.
6. Controleer in **Monitoring → Batches** of de notificaties van de batch nu allemaal een vinkje hebben bij **Verwerkt**.

Mislukt de job opnieuw, dan verschijnt er een **nieuwe** mislukte job in Horizon. Start vanaf dat moment alleen die nieuwste opnieuw, niet de oude.

## Opnieuw verwerken via de command line

Horizon bewaart mislukte jobs 7 dagen. Daarna, of als Horizon niet bereikbaar is, gebruik je de `failed_jobs`-tabel via Artisan.

Mislukte jobs tonen:

```bash
php artisan queue:failed
```

Eén job opnieuw starten, met de UUID uit het overzicht:

```bash
php artisan queue:retry <uuid>
```

Lokaal met Sail gebruik je `./vendor/bin/sail artisan` in plaats van `php artisan`.

> Gebruik `queue:retry all` alleen als je zeker weet dat geen van de jobs al via Horizon opnieuw is gestart. Anders worden notificaties dubbel naar Corsa gestuurd.

Een job die je via de command line opnieuw hebt gestart, blijft in Horizon als mislukt zichtbaar. Start hem daar niet nog een keer.

## Wanneer niet opnieuw starten

Opnieuw starten helpt alleen bij tijdelijke fouten, zoals een time-out of een storing bij Open Zaak of Corsa. Bij de volgende fouten mislukt de job opnieuw zolang de oorzaak niet is opgelost:

| Foutmelding | Oorzaak | Wat te doen |
|---|---|---|
| `DisallowedHostException` / "not a configured ZGW host" | De notificatie verwijst naar een URL buiten de geconfigureerde ZGW-hosts. | Niet opnieuw starten. Controleer de configuratie of de bron van de notificatie. |
| `Missing zaak in Corsa for ...` | De zaak bestaat niet in Corsa, bijvoorbeeld omdat `create:zaak` in een eerdere batch mislukte. | Verwerk eerst de mislukte `create:zaak` van die zaak opnieuw, daarna deze job. |
| `Missing ... url for ...` | De notificatie mist een verplicht veld. | Niet opnieuw starten; de payload verandert niet. Bekijk de payload via **Bekijk payload** in de batch. |
| Fouten bij het mappen van de zaak (bijv. `Undefined array key`, `TypeError`) | De zaakgegevens passen niet in de mapping naar Corsa. | Eerst de code of de zaakgegevens aanpassen, daarna opnieuw starten. |

## Controle na opnieuw starten

- Alle notificaties van de batch hebben een vinkje bij **Verwerkt**.
- In Horizon staat er voor de zaak geen nieuwe mislukte job.
- Bij een opnieuw gestarte `create:zaakinformatieobject`: controleer in Corsa dat het document er één keer staat.
