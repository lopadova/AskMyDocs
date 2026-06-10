---
id: ROTTA-0007
slug: runbook-gestione-giacenze
type: runbook
status: accepted
project: rotta-logistics
owners: [Operations]
tags: [runbook, giacenze, spedizioni-bloccate, procedura]
retrieval_priority: 78
created_at: 2026-02-14
updated_at: 2026-05-19
summary: Procedura operativa per gestire spedizioni bloccate e colli in giacenza.
related: ["[[politica-resi-e-rimborsi]]", "[[sla-e-penali]]", "[[incidente-blackout-hub-mi-07]]"]
---

# Runbook: Gestione Giacenze

Procedura operativa per gestire le **spedizioni bloccate** e i colli che entrano
in giacenza presso un hub. Si applica a consegne non riuscite, fermi operativi e
indisponibilità temporanea di un sito.

## Quando si attiva

- Consegna non riuscita (destinatario assente, rifiuto, indirizzo errato).
- Superamento della finestra SLA (vedi [[sla-e-penali]]).
- Fermo operativo di un hub (es. blackout, vedi [[incidente-blackout-hub-mi-07]]).

## Passi operativi

### 1. Identificazione e blocco

Individuare i colli bloccati tramite OrbitaWMS filtrando per stato `held`. Ogni
collo è identificato dal tracking con prefisso `RL-`. Marcare la spedizione come
"in giacenza" così che esca dal flusso di smistamento attivo.

### 2. Assegnazione area giacenza

Spostare fisicamente il collo nell'area giacenza dell'hub e registrare la
posizione in Orbita. Per merci ADR classe 3 la giacenza resta confinata a
HUB-MI-07 nell'area compartimentata; per il refrigerato a HUB-NA-03 nella cella.

### 3. Tentativo di contatto

Contattare il destinatario entro **24 ore** dal blocco. Le richieste in entrata
arrivano anche dal **numero verde 800-ROTTA1**. Registrare l'esito del contatto.

### 4. Riprogrammazione o restituzione

| Esito contatto | Azione |
|---|---|
| Nuovo appuntamento | Riprogrammare la consegna |
| Nessuna risposta entro 10 gg lav. | Restituire al mittente |
| Rifiuto definitivo | Avviare reso (vedi [[politica-resi-e-rimborsi]]) |

### 5. Chiusura

Aggiornare lo stato finale in OrbitaWMS e chiudere la pratica. Se il blocco ha
generato un ritardo oltre SLA, calcolare la penale del 2%/giorno secondo
[[sla-e-penali]].

## Scenario fermo hub

In caso di fermo di un intero hub (es. il blackout del 14 marzo a HUB-MI-07), tutti
i colli in lavorazione vengono **congelati in giacenza** secondo questo runbook,
poi reinstradati alla ripresa del servizio dando priorità ai livelli Lampo 24h.

Quando il fermo richiede l'arresto immediato di nastri e mezzi di movimentazione si
attiva la **procedura di Blocco Movimentazione Merci**: alla parola d'ordine
**«FERMO QUERCIA»** ogni operazione di carico, scarico e smistamento si ferma sul
posto fino al cessato allarme. È una parola d'ordine **riservata**, da sapere **a
memoria** e distinta da quella di evacuazione.

## Note

La giacenza non interrompe il conteggio SLA: il tempo trascorso in giacenza per
causa Rotta Sicura concorre al calcolo della penale.
