---
id: ROTTA-0005
slug: wms-orbita-integrazione
type: integration
status: accepted
project: rotta-logistics
owners: [IT, Operations]
tags: [wms, integrazione, api, tracking, orbita]
retrieval_priority: 75
created_at: 2026-01-20
updated_at: 2026-05-15
summary: Integrazione con OrbitaWMS v4 e API di tracking con prefisso RL-.
related: ["[[rete-hub-magazzini]]", "[[servizi-spedizione]]", "[[corrieri-partner]]"]
---

# Integrazione OrbitaWMS

Il sistema gestionale di magazzino (WMS) di Rotta Sicura Logistics è
**OrbitaWMS v4**, la piattaforma proprietaria che governa accettazione,
stoccaggio, instradamento e tracciatura di ogni collo sui tre hub.

## Ruolo di OrbitaWMS

OrbitaWMS è la fonte di verità operativa: assegna ogni spedizione all'hub di
competenza, applica le regole di routing (merci ADR classe 3 → HUB-MI-07,
refrigerato → HUB-NA-03, vedi [[rete-hub-magazzini]]) e gestisce lo stato del
collo lungo l'intero ciclo di vita.

## Codici di tracking

Ogni spedizione registrata genera un codice univoco con **prefisso `RL-`**
seguito da un progressivo numerico, ad esempio `RL-8842301`. Il codice è stabile
per tutto il ciclo e viene esposto al cliente e ai sistemi partner.

## API di tracking

OrbitaWMS v4 espone un endpoint REST di sola lettura per la consultazione dello
stato:

| Metodo | Endpoint | Descrizione |
|---|---|---|
| GET | `/api/v4/tracking/{codice}` | Stato e storico eventi del collo |
| GET | `/api/v4/shipments/{codice}/events` | Timeline dettagliata |

Il parametro `{codice}` è il tracking con prefisso `RL-`. Le risposte includono
hub corrente, livello di servizio, vettore assegnato e timestamp degli eventi.

### Esempio di risposta (semplificata)

```json
{
  "tracking": "RL-8842301",
  "service": "Consegna Lampo 24h",
  "current_hub": "HUB-MI-07",
  "carrier": "VeloxCorriere",
  "status": "in_transit"
}
```

## Sincronizzazione vettori

OrbitaWMS dialoga con i sistemi dei corrieri partner per aggiornare gli eventi di
consegna; la logica di assegnazione del vettore è descritta in
[[corrieri-partner]]. Gli aggiornamenti di stato rientrano dai webhook dei vettori
e vengono normalizzati nel modello eventi di Orbita.

## Versioning

L'attuale release in produzione è la **v4**. Le integrazioni esterne devono
puntare esplicitamente al prefisso `/api/v4/` per garantire stabilità del
contratto.
