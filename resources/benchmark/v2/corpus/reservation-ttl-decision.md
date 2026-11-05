---
id: DEC-WH-007
slug: reservation-ttl-decision
type: decision
status: accepted
retrieval_priority: 85
tags: [warehouse, stock, reservation, ttl]
owners: [warehouse-platform-team]
summary: Durata delle prenotazioni di stock - 15 minuti per il carrello web, 48 ore per ordini B2B, 4 ore per ordini telefonici.
related:
  - "[[order-lifecycle-concept]]"
---

# Durata delle prenotazioni di stock

Quando un cliente mette un prodotto nel carrello la quantità viene riservata
per evitare vendite doppie. La riserva ha una scadenza che dipende dal canale.

## Carrelli web

La prenotazione dura 15 minuti dal momento in cui il prodotto entra nel
carrello. Al checkout può essere estesa una sola volta di 10 minuti.

## Ordini B2B

Le prenotazioni degli ordini B2B durano 48 ore.

## Ordini telefonici

Le prenotazioni inserite dall'operatore del call center durano 4 ore.

## Rilascio automatico

Il job `ReleaseExpiredReservations` gira ogni minuto e libera le
prenotazioni scadute, rimettendo la quantità a disposizione.

## Limite per cliente

Un singolo cliente non può prenotare più del 20% della giacenza di uno SKU.
Il ciclo di vita completo dell'ordine è descritto in [[order-lifecycle-concept]].
