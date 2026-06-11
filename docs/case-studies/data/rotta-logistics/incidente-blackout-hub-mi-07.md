---
id: INC-2026-0314
slug: incidente-blackout-hub-mi-07
type: incident
status: accepted
project: rotta-logistics
owners: [Operations, IT]
tags: [incidente, blackout, hub-mi-07, continuita]
retrieval_priority: 72
created_at: 2026-03-15
updated_at: 2026-03-28
summary: Blackout elettrico a HUB-MI-07 del 14 marzo, 6 ore di fermo, gestito col runbook giacenze.
related: ["[[rete-hub-magazzini]]", "[[runbook-gestione-giacenze]]", "[[merci-pericolose-adr]]"]
---

# Incidente: Blackout HUB-MI-07 (14 marzo)

## Sintesi

Il **14 marzo** un **blackout elettrico** ha colpito **HUB-MI-07** (Milano,
Pioltello), causando un **fermo operativo di 6 ore**. L'evento è stato gestito
applicando il [[runbook-gestione-giacenze]] senza perdita di merci.

## Cronologia

| Ora | Evento |
|---|---|
| 08:10 | Interruzione fornitura elettrica all'intero sito |
| 08:25 | Sistemi OrbitaWMS isolati; baie di carico ferme |
| 08:40 | Attivazione runbook giacenze: colli in lavorazione congelati |
| 11:30 | Verifica integrità area ADR classe 3 (nessuna anomalia) |
| 14:10 | Ripristino fornitura e riavvio sistemi |
| 14:20 | Ripresa smistamento con priorità ai colli Lampo 24h |

## Impatto

- **Durata fermo**: 6 ore.
- **Hub coinvolto**: solo HUB-MI-07; HUB-NA-03 e HUB-RM-05 operativi.
- **Colli**: tutti messi in giacenza temporanea e reinstradati a ripristino.
- **Merci pericolose**: l'area ADR classe 3 è rimasta compartimentata e
  monitorata; nessuna dispersione (vedi [[merci-pericolose-adr]]).

## Causa

Guasto alla cabina di trasformazione di zona, esterno al sito. Il gruppo di
continuità ha coperto i soli sistemi critici (sicurezza e monitoraggio area ADR),
non l'intera movimentazione.

## Azioni correttive

1. Valutazione di un generatore di backup dimensionato per la movimentazione
   essenziale, non solo per i sistemi di sicurezza.
2. Test trimestrale di failover di OrbitaWMS.
3. Procedura di notifica proattiva ai clienti tramite numero verde 800-ROTTA1 per
   le spedizioni Lampo 24h impattate.

## Esito SLA

I colli urbani in coda hanno sforato la finestra delle 24h: per le spedizioni
impattate è stata applicata la gestione penali secondo [[sla-e-penali]], con
riconoscimento ai clienti B2B coinvolti.

## Stato

Incidente **chiuso**. Le azioni correttive 1 e 2 sono in fase di implementazione.
