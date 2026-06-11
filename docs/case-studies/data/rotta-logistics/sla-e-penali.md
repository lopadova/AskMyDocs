---
id: DEC-2026-0007
slug: sla-e-penali
type: decision
status: accepted
project: rotta-logistics
owners: [Direzione Operations]
tags: [sla, penali, qualita, decisione]
retrieval_priority: 80
created_at: 2026-02-03
updated_at: 2026-05-10
summary: Definizione dei target SLA di consegna e delle penali per ritardo.
related: ["[[servizi-spedizione]]", "[[runbook-gestione-giacenze]]", "[[politica-resi-e-rimborsi]]"]
---

# Decisione: Target SLA e Penali

## Contesto

I clienti B2B richiedono garanzie contrattuali misurabili sulla puntualità delle
consegne. Questa decisione fissa il target SLA di riferimento e il regime di
penali applicato in caso di ritardo, valido per tutti i contratti standard.

## Decisione

### Target SLA — consegna urbana

Per la **consegna urbana** il target è il **98% delle spedizioni consegnate
entro 24 ore** dalla presa in carico. La misura è calcolata su base mensile per
ciascun hub e aggregata a livello di rete.

### Penale per ritardo

In caso di mancato rispetto del tempo di resa contrattuale si applica una
**penale pari al 2% del valore dell'ordine per ogni giorno di ritardo**. La
penale matura dal primo giorno lavorativo successivo alla scadenza prevista e si
cumula fino alla consegna effettiva o alla gestione a giacenza.

| Parametro | Valore |
|---|---|
| Target SLA urbano | 98% entro 24h |
| Penale per giorno di ritardo | 2% del valore ordine |
| Base di calcolo SLA | Mensile, per hub |

## Razionale

- Il 98% è raggiungibile con l'attuale capacità della rete a tre hub senza
  sovradimensionare i mezzi.
- La penale al 2%/giorno è proporzionata: scoraggia il ritardo senza azzerare la
  marginalità su un singolo disservizio.
- Il calcolo per giorno (e non forfettario) premia il recupero rapido.

## Conseguenze operative

I ritardi che superano la finestra SLA attivano il [[runbook-gestione-giacenze]],
che disciplina la presa in carico delle spedizioni bloccate. Le ricadute su resi
e rimborsi sono trattate in [[politica-resi-e-rimborsi]].

## Stato

Decisione **accettata** e in vigore per tutti i nuovi contratti. Le revisioni dei
target avvengono su base annuale.
