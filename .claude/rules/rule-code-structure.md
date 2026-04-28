# Regola: Code Structure

## Obiettivo

Scrivere codice leggibile, piatto e facile da modificare.

## Regole

- Preferisci guard clauses ed early return.
- Evita `else` quando un return o continue chiarisce il flusso.
- Tieni piccoli i metodi: una responsabilita' principale per funzione.
- Sposta rami complessi in metodi privati o servizi dedicati.
- Commenta decisioni, vincoli o tradeoff; non commentare l'ovvio.
- Evita helper "magici" se un oggetto esplicito rende meglio il contratto.

## Anti-pattern

- Funzioni da 150+ righe con piu' responsabilita'
- logica di business mescolata a I/O o rendering
- boolean flag che cambiano radicalmente il comportamento di un metodo
- nomi come `data`, `tmp`, `manager`, `utils` senza contesto
