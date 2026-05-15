---
name: admin-interface-frontend
description: Implementare la parte frontend di una pagina admin complessa Laravel — entrypoint pagina, api client, gestione filtri, rendering tabella/KPI/charts, stati empty/loading/error. Trigger quando si tocca un componente sotto frontend/src/features/admin/ o resources/views/admin/, quando si implementa la UI di un dashboard / wizard / modal admin, o quando si refactora una vista admin esistente.
---

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
