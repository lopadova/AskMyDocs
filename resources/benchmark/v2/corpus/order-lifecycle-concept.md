---
id: CON-ORD-012
slug: order-lifecycle-concept
type: domain-concept
status: accepted
retrieval_priority: 80
tags: [orders, lifecycle, states, domain]
owners: [fulfilment-platform-team]
summary: Gli stati di un ordine Northwind da PLACED a DELIVERED, con CANCELLED e RETURNED come rami laterali.
related:
  - "[[reservation-ttl-decision]]"
  - "[[refund-policy]]"
---

# Ciclo di vita dell'ordine

## Percorso principale

PLACED, PAID, ALLOCATED, PICKED, PACKED, SHIPPED, DELIVERED. Ogni passaggio
produce un evento che alimenta tracking e fatturazione.

## Stati laterali

- **CANCELLED**: consentito fino allo stato PICKED incluso.
- **RETURNED**: raggiungibile solo da DELIVERED, secondo la [[refund-policy]].

## Regole di transizione

Un ordine PAID non può tornare a PLACED. L'allocazione dello stock usa le
prenotazioni descritte in [[reservation-ttl-decision]].

## Obiettivi di servizio

- Allocazione entro 10 minuti dal pagamento.
- Spedizione entro 24 ore lavorative dallo stato ALLOCATED.
- Notifica al cliente a ogni cambio di stato visibile.
