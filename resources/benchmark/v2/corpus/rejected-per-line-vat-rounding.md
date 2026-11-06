---
id: REJ-BIL-001
slug: rejected-per-line-vat-rounding
type: rejected-approach
status: accepted
retrieval_priority: 70
tags: [billing, vat, iva, rejected]
owners: [finance-team]
summary: Perché abbiamo scartato l'arrotondamento dell'IVA riga per riga.
related:
  - "[[vat-rounding-standard]]"
---

# Scartato: arrotondare l'IVA riga per riga

Abbiamo valutato di calcolare e arrotondare l'IVA su ogni singola riga di
fattura, sommando poi i risultati.

## Perché è stato scartato

- **Scarti di centesimi.** In un campione di 10.000 fatture, 412 mostravano
  una differenza di almeno 1 centesimo rispetto al totale calcolato dal
  sistema di interscambio e dalla contabilità.
- **Fatture lunghe.** Su fatture da 50 righe lo scarto arrivava a 0,05 EUR.
- **Note di credito disallineate.** Il rimborso parziale non riproduceva
  l'IVA della fattura originale.

L'alternativa accettata è lo standard sul totale per aliquota
([[vat-rounding-standard]]). Non riproporre l'arrotondamento per riga.
