# Regola: Frontend JavaScript e CSS Best Practices

## JavaScript

- Evita inline handlers come `onclick`.
- Gestisci stati loading, error e empty in modo esplicito.
- Non duplicare listener o istanze di componenti stateful.
- Distruggi o resetta gli oggetti prima di re-render complessi.
- Preferisci selector stabili e contratti DOM intenzionali.

## CSS

- Evita hardcode ridondanti se esiste un sistema di token o variabili.
- Mantieni locali gli stili locali; non sporcare il bundle globale.
- Evita specificita' eccessiva e classi opache.
- Progetta per stati focus, hover, disabled e loading.

## HTML/UX

- Le azioni asincrone devono dare feedback visivo.
- Nessuna UI dipendente solo dal colore.
- Placeholder e skeleton devono rispettare il layout finale.
