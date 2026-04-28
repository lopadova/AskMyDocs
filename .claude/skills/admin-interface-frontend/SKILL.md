# Admin Interface Frontend

Skill per la parte frontend di una pagina admin complessa.

## Moduli tipici

- entrypoint della pagina
- api client
- gestione filtri
- rendering tabella
- rendering KPI/charts
- gestione stati empty/loading/error

## Regole

- niente URL hardcoded nel JS se la view puo' passarle in `data-*`
- loading e disabled state obbligatori sulle azioni asincrone
- event delegation per liste o tabelle dinamiche
- cleanup di grafici, modal o istanze prima del re-render
