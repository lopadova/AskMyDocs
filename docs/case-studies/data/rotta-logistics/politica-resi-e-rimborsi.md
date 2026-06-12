---
id: ROTTA-0006
slug: politica-resi-e-rimborsi
type: standard
status: accepted
project: rotta-logistics
owners: [Customer Care, Operations]
tags: [resi, rimborsi, giacenze, policy]
retrieval_priority: 70
created_at: 2026-02-10
updated_at: 2026-05-12
summary: Gestione di resi, giacenze e rimborsi nel servizio logistico.
related: ["[[runbook-gestione-giacenze]]", "[[sla-e-penali]]", "[[servizi-spedizione]]"]
---

# Politica Resi e Rimborsi

Come operatore logistico, Rotta Sicura Logistics non gestisce il "reso commerciale"
del prodotto (che resta in capo al mittente/venditore), ma il **flusso fisico**
del collo che torna indietro e l'eventuale rimborso del servizio di spedizione.

## Tipologie di rientro

| Caso | Descrizione | Esito tipico |
|---|---|---|
| Reso al mittente | Destinatario rifiuta o irreperibile | Ritorno al deposito di origine |
| Giacenza | Consegna non riuscita, collo trattenuto in hub | Riconsegna o restituzione |
| Avaria | Collo danneggiato in transito | Apertura sinistro |

## Giacenze

Quando una consegna non va a buon fine (destinatario assente, indirizzo errato,
rifiuto), il collo entra in **giacenza** presso l'hub di competenza. La gestione
operativa segue il [[runbook-gestione-giacenze]]. La giacenza standard è
mantenuta per un massimo di **10 giorni lavorativi**, dopo i quali il collo è
restituito al mittente.

## Rimborsi del servizio di spedizione

Il rimborso riguarda il **costo del servizio**, non il valore della merce:

- **Mancata consegna per causa Rotta Sicura**: rimborso integrale della tariffa
  di spedizione, oltre alle penali previste in [[sla-e-penali]].
- **Ritardo oltre SLA**: applicazione della penale del 2% del valore ordine per
  giorno di ritardo; la tariffa di spedizione non viene rimborsata se il collo
  è comunque consegnato.
- **Avaria imputabile al trasporto**: gestione tramite sinistro assicurativo, con
  perizia sul collo.

I supplementi (isole +€4,90, contrassegno +€3,50) seguono la sorte della tariffa
base: se la spedizione è rimborsata integralmente, sono rimborsati anch'essi.

## Apertura pratica

Le richieste di reso, giacenza o rimborso si aprono tramite il **numero verde
800-ROTTA1** o dal portale clienti, indicando il codice di tracking `RL-`. Ogni
pratica è tracciata in OrbitaWMS fino alla chiusura.
