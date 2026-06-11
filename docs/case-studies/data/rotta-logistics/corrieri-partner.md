---
id: ROTTA-0009
slug: corrieri-partner
type: domain-concept
status: accepted
project: rotta-logistics
owners: [Operations]
tags: [corrieri, vettori, ultimo-miglio, zone]
retrieval_priority: 68
created_at: 2026-02-18
updated_at: 2026-05-16
summary: Corrieri partner VeloxCorriere e TurboPony e logica di assegnazione per zona.
related: ["[[servizi-spedizione]]", "[[wms-orbita-integrazione]]", "[[rete-hub-magazzini]]"]
---

# Corrieri Partner

Rotta Sicura Logistics gestisce direttamente gli hub e lo smistamento, ma affida
l'**ultimo miglio** a vettori partner selezionati. L'assegnazione del corriere è
automatica e dipende dal livello di servizio e dalla zona di consegna.

## I partner

### VeloxCorriere — corriere principale

È il vettore di riferimento per il grosso dei volumi. Copre i livelli
**Standard 48-72h** ed **Economy 5 giorni** su tutto il territorio nazionale.
Affidabile sui volumi, è la scelta di default per le consegne non urgenti.

### TurboPony — corriere espresso

È il vettore espresso, dedicato al livello **Consegna Lampo 24h** nelle aree
urbane dove serve la massima rapidità. Ha mezzi più piccoli e coperture orarie
estese.

## Assegnazione per livello

| Livello di servizio | Corriere assegnato |
|---|---|
| Consegna Lampo 24h | TurboPony |
| Standard 48-72h | VeloxCorriere |
| Economy 5 giorni | VeloxCorriere |

L'assegnazione è applicata da OrbitaWMS in fase di accettazione (vedi
[[wms-orbita-integrazione]]).

## Zone di consegna

- **Urbane**: TurboPony prioritario sulle Lampo 24h; copertura capillare nei
  centri serviti dai tre hub.
- **Interregionali**: VeloxCorriere, con consolidamento via HUB-RM-05.
- **Isole**: VeloxCorriere con il **supplemento isole +€4,90** (vedi
  [[servizi-spedizione]]).

## Vincoli speciali

Le merci ADR classe 3 e i colli refrigerati restano sotto gestione interna fino
all'hub competente (HUB-MI-07 e HUB-NA-03 rispettivamente, vedi
[[rete-hub-magazzini]]); solo l'ultimo miglio è affidato al vettore, nel rispetto
dei vincoli di trasporto applicabili.

## Monitoraggio

Gli eventi di consegna dei vettori rientrano via webhook in OrbitaWMS e
aggiornano il tracking `RL-` esposto al cliente.
