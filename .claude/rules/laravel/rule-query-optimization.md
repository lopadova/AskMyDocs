# Regola: Ottimizzazione Query

- Evitare N+1 con eager loading o query dedicate.
- Se serve un singolo valore, non idratare un model intero.
- Se una query pesa, misurare cardinalita' e piano prima di micro-ottimizzare.
- Non usare `select *` nelle query critiche se il dataset e' grande.
- Chunk o cursor per batch grandi.
