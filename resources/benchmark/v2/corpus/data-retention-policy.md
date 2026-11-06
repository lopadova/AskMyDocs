---
id: STD-DAT-008
slug: data-retention-policy
type: standard
status: accepted
retrieval_priority: 85
tags: [data, retention, gdpr, privacy]
owners: [dpo-office]
summary: Periodi di conservazione dei dati Northwind - log 90 giorni, fatturazione 10 anni, ordini 24 mesi, candidature 6 mesi.
related:
  - "[[order-lifecycle-concept]]"
---

# Politica di conservazione dei dati
Approvata dal DPO con la richiesta DPO-118. Ogni categoria ha un periodo
massimo; oltre la scadenza i dati vengono cancellati o anonimizzati.

## Log applicativi
Il periodo di conservazione è 90 giorni.

## Log di sicurezza e accesso
Il periodo di conservazione è 12 mesi.

## Dati di fatturazione
Il periodo di conservazione è 10 anni, per obbligo fiscale.

## Ordini dei clienti
Il periodo di conservazione è 24 mesi dopo l'ultima attività dell'account
(vedi anche [[order-lifecycle-concept]]).

## Candidature
I CV dei candidati non assunti si conservano per 6 mesi dalla chiusura
della selezione.

## Registrazioni delle chiamate di supporto
Il periodo di conservazione è 60 giorni.

## Esecuzione
Lo sweeper di retention gira ogni notte alle 02:30 UTC e produce un report
con il numero di file eliminati per categoria.
