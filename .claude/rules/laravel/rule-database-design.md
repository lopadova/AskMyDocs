# Regola: Database Design

- Ogni tabella deve avere una responsabilita' chiara.
- Aggiungere indici in base ai pattern di lettura reali.
- Evitare colonne ambigue che codificano piu' concetti insieme.
- Per dati derivati o denormalizzati, documentare la fonte di verita'.
- Valutare cardinalita', unique constraints e nullability con attenzione.

## Query-driven design

- se una query critica filtra e ordina sugli stessi campi, modellare l'indice di conseguenza
- usare foreign key quando lo stack e il contesto lo consentono
