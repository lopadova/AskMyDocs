---
name: admin-interface-component-audit
description: Audit di componenti UI e servizi esistenti PRIMA di creare una nuova interfaccia admin, per decidere REUSE / EXTEND / CREATE-DOMAIN / CREATE-GLOBAL su ciascun pezzo. Default a REUSE. Trigger quando l'utente chiede di costruire una nuova pagina admin, un nuovo dashboard, un nuovo wizard o di refactorare interfacce admin disomogenee — sempre prima di scrivere codice frontend nuovo.
---

# Component Audit

Prima di creare una nuova interfaccia admin:

1. elenca componenti UI gia' presenti
2. elenca servizi o helper gia' esistenti
3. per ogni elemento decidi:
   - REUSE
   - EXTEND
   - CREATE-DOMAIN
   - CREATE-GLOBAL

## Regola principale

Default a REUSE. Creare nuovo codice solo se il riuso peggiora chiarezza o correttezza.

## Output

Tabella con elemento, decisione, path esistente e motivo.
