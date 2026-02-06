# API Call volgoorde

Volgordelijkheid zoals beneden

## 1. Per zaak: 
- Get zaak
- ZaakDMS: Creeer zaak

```json
{
  "actie": "create",
  "kanaal": "zaken",
  "resource": "zaak",
  "kenmerken": {
    "zaaktype": "https://mijn-services.accp.nijmegen.nl/open-zaak/catalogi/api/v1/zaaktypen/a2896f94-78f8-45ec-b735-aae8cee7533f",
    "bronorganisatie": "001479179",
    "zaaktype.catalogus": "https://mijn-services.accp.nijmegen.nl/open-zaak/catalogi/api/v1/catalogussen/0da8064f-0604-49cb-9ed9-56fb8afc99b5",
    "vertrouwelijkheidaanduiding": "zaakvertrouwelijk"
  },
  "hoofdObject": "https://mijn-services.accp.nijmegen.nl/open-zaak/zaken/api/v1/zaken/7dd707a3-99fb-477d-a241-941d79fadb7c",
  "resourceUrl": "https://mijn-services.accp.nijmegen.nl/open-zaak/zaken/api/v1/zaken/7dd707a3-99fb-477d-a241-941d79fadb7c",
  "aanmaakdatum": "2026-02-03T14:28:55.974Z"
}
```

## 2. Per status wijziging:
- Get zaakstatus
- ZaakDMS: Update zaak

```json
{
  "actie": "create",
  "kanaal": "zaken",
  "resource": "status",
  "kenmerken": {
    "zaaktype": "https://mijn-services.accp.nijmegen.nl/open-zaak/catalogi/api/v1/zaaktypen/a2896f94-78f8-45ec-b735-aae8cee7533f",
    "bronorganisatie": "001479179",
    "zaaktype.catalogus": "https://mijn-services.accp.nijmegen.nl/open-zaak/catalogi/api/v1/catalogussen/0da8064f-0604-49cb-9ed9-56fb8afc99b5",
    "vertrouwelijkheidaanduiding": "zaakvertrouwelijk"
  },
  "hoofdObject": "https://mijn-services.accp.nijmegen.nl/open-zaak/zaken/api/v1/zaken/7dd707a3-99fb-477d-a241-941d79fadb7c",
  "resourceUrl": "https://mijn-services.accp.nijmegen.nl/open-zaak/zaken/api/v1/statussen/016501ff-fb6e-4761-b454-2d21516cef18",
  "aanmaakdatum": "2026-02-03T14:28:56.890Z"
}
```


## 3. Per document (notificatie: zaakinfomatieobject):
- Echte document referentie ophalen via ZGW (via expand op resourceUrl)
- Echte document ophalen
- ZaakDMS: Voeg zaak document toe

```json
{
  "actie": "create",
  "kanaal": "zaken",
  "resource": "zaakinformatieobject",
  "kenmerken": {
    "zaaktype": "https://mijn-services.accp.nijmegen.nl/open-zaak/catalogi/api/v1/zaaktypen/a2896f94-78f8-45ec-b735-aae8cee7533f",
    "bronorganisatie": "001479179",
    "zaaktype.catalogus": "https://mijn-services.accp.nijmegen.nl/open-zaak/catalogi/api/v1/catalogussen/0da8064f-0604-49cb-9ed9-56fb8afc99b5",
    "vertrouwelijkheidaanduiding": "zaakvertrouwelijk"
  },
  "hoofdObject": "https://mijn-services.accp.nijmegen.nl/open-zaak/zaken/api/v1/zaken/7dd707a3-99fb-477d-a241-941d79fadb7c",
  "resourceUrl": "https://mijn-services.accp.nijmegen.nl/open-zaak/zaken/api/v1/zaakinformatieobjecten/fc84da71-8e17-4d97-97b1-a4a4dba33a38",
  "aanmaakdatum": "2026-02-03T14:28:59.373Z"
}
```




## Niet nodig: 
- ZaakDMS: Genereer zaakidentificatie
- ZaakDMS: Genereer document identificatie
- ZaakDMS: Get calls