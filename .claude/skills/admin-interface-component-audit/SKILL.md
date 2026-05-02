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
