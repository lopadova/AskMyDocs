---
id: ROTTA-0011
slug: rejected-consegna-droni
type: rejected-approach
status: accepted
project: rotta-logistics
owners: [Direzione, Operations]
tags: [rejected, droni, ultimo-miglio, valutazione]
retrieval_priority: 60
created_at: 2026-03-05
updated_at: 2026-04-30
summary: Valutazione e scarto della consegna con droni per l'ultimo miglio.
related: ["[[corrieri-partner]]", "[[servizi-spedizione]]", "[[rete-hub-magazzini]]"]
---

# Approccio Scartato: Consegna con Droni

## Sintesi

Rotta Sicura Logistics ha valutato l'adozione della **consegna con droni** per
l'ultimo miglio urbano sul livello Lampo 24h e ha deciso di **scartarla**. Questo
documento spiega perché, così da non riproporre l'idea senza nuovi elementi.

## Cosa era stato proposto

Sostituire o affiancare i corrieri espresso (oggi TurboPony, vedi
[[corrieri-partner]]) con una flotta di droni per consegne rapide di colli leggeri
nei centri urbani serviti dai tre hub, puntando a tempi sotto le 2 ore.

## Perché è stato scartato

### 1. Vincoli normativi ENAC

Lo spazio aereo urbano italiano è soggetto a regole stringenti
dell'**ENAC** (Ente Nazionale per l'Aviazione Civile): autorizzazioni per
operazioni BVLOS, no-fly zone sui centri abitati densi, limiti di peso e quota,
e iter autorizzativi lunghi e per singola tratta. La copertura capillare richiesta
dalle nostre zone urbane non è compatibile con questo quadro.

### 2. Costi insostenibili

Il costo per consegna risultava **molto superiore** a quello dell'ultimo miglio
tradizionale: flotta, manutenzione, piloti/operatori certificati, assicurazioni
e infrastruttura di ricarica non sono ammortizzabili sui volumi e sulle tariffe
attuali. Trattandosi di un operatore logistico senza spedizione gratuita, il
sovraccosto sarebbe ricaduto sul cliente rendendo il servizio non competitivo.

### 3. Limiti operativi

- Payload ridotto: incompatibile con la varietà di pesi/volumi che movimentiamo.
- Incompatibilità totale con merci ADR classe 3 e con il refrigerato (vedi
  [[rete-hub-magazzini]]).
- Dipendenza dalle condizioni meteo, in conflitto con gli SLA Lampo 24h.

## Decisione

L'ultimo miglio resta affidato ai corrieri partner su gomma. La consegna con
droni è **scartata** allo stato attuale di normativa e costi.

## Condizioni per una riconsiderazione

Si potrà rivalutare solo se: (a) ENAC semplifica le autorizzazioni BVLOS urbane,
e (b) il costo per consegna scende sotto la soglia dell'ultimo miglio su gomma.
Fino ad allora, non riproporre questo approccio.
