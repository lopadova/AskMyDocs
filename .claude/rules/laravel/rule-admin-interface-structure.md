# Regola: Struttura Interfacce Admin Complesse

## Principi

- Backend e frontend separati.
- Controller thin: request -> service -> response.
- Contratto dati stabile tra BE e FE.
- Stati UI obbligatori: initial, loading, success, error.
- Route, view, JS e test devono avere naming coerente.

## Sezioni tipiche

- header
- filtri
- KPI
- tabella o lista
- export
- modal o drill-down

## Anti-pattern

- logica business nel controller
- URL hardcoded nel JS
- nessun empty state
- nessun cleanup di componenti con stato
