---
id: ROTTA-0010
slug: merci-pericolose-adr
type: standard
status: accepted
project: rotta-logistics
owners: [Operations, HSE]
tags: [adr, merci-pericolose, sicurezza, hub-mi-07]
retrieval_priority: 76
created_at: 2026-02-22
updated_at: 2026-05-14
summary: Gestione delle merci pericolose ADR classe 3 presso HUB-MI-07.
related: ["[[rete-hub-magazzini]]", "[[runbook-gestione-giacenze]]", "[[incidente-blackout-hub-mi-07]]"]
---

# Gestione Merci Pericolose ADR Classe 3

Rotta Sicura Logistics movimenta merci pericolose della **classe ADR 3**
(liquidi infiammabili) esclusivamente attraverso un unico nodo di rete abilitato:
**HUB-MI-07** (Milano, Pioltello).

## Perimetro

La classe 3 ADR comprende liquidi infiammabili quali solventi, vernici, alcolici
tecnici e profumeria con base alcolica. Tutte le altre classi ADR non sono
attualmente in perimetro e non vengono accettate.

## Hub abilitato

HUB-MI-07 è l'**unico hub abilitato** alla movimentazione ADR classe 3, in quanto
dispone di:

- area di stoccaggio **compartimentata** e ventilata;
- sistemi di rilevazione e contenimento;
- personale con **patentino ADR** e formazione antincendio.

Gli hub HUB-NA-03 e HUB-RM-05 **non** possono accettare merci classe 3: ogni
spedizione ADR è instradata da OrbitaWMS verso HUB-MI-07 (vedi
[[rete-hub-magazzini]]).

## Flusso operativo

1. **Accettazione**: verifica della scheda di sicurezza e della corretta
   etichettatura UN; rifiuto se la documentazione è incompleta.
2. **Stoccaggio**: collocazione nell'area compartimentata, separata dal flusso
   ordinario.
3. **Trasporto**: instradamento con mezzi e documentazione ADR conformi; consegna
   ultimo miglio solo tramite vettori autorizzati.

## Continuità e giacenze

In caso di fermo hub, i colli ADR seguono una procedura rafforzata: restano
nell'area compartimentata e monitorata. Durante il blackout del 14 marzo (vedi
[[incidente-blackout-hub-mi-07]]) l'area ADR è rimasta sotto continuità elettrica
dedicata e non ha registrato anomalie. La giacenza di merci classe 3 segue il
[[runbook-gestione-giacenze]] con il vincolo di permanenza nell'area dedicata.

## Limiti

I quantitativi accettati rispettano le soglie di esenzione e i limiti di stoccaggio
autorizzati per il sito. Superate tali soglie, la spedizione è rifiutata in
accettazione.
