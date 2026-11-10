---
id: STD-BIL-005
slug: vat-rounding-standard
type: standard
status: accepted
retrieval_priority: 85
tags: [billing, vat, iva, rounding]
owners: [finance-team]
summary: L'IVA si calcola sul totale imponibile per aliquota e si arrotonda half-up al centesimo.
related:
  - "[[refund-policy]]"
---

# Standard di arrotondamento IVA

## Regola

L'IVA si calcola sul totale imponibile di ciascuna aliquota, non riga per
riga. Il risultato è arrotondato al centesimo con la regola half-up (0,5
arrotonda per eccesso).

## Aliquote

In Italia: 22% ordinaria, 10% ridotta, 4% minima.

## Sconti

Lo sconto di riga si applica prima dell'IVA. L'IVA non viene mai calcolata
sul prezzo scontato di una singola unità per poi essere moltiplicata.

## Esempio

Tre righe da 10,03 EUR con aliquota 22%: imponibile 30,09 EUR, IVA 6,6198
che diventa 6,62 EUR, totale 36,71 EUR. Con l'arrotondamento per riga si
sarebbe ottenuto 3 x 2,21 = 6,63 EUR.

## Note di credito

Le note di credito emesse per i rimborsi seguono la stessa identica regola
([[refund-policy]]).
