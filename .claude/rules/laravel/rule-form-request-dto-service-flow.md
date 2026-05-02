# Regola: FormRequest -> DTO -> Service Flow

Per endpoint o form non banali, usare questo flusso come default:

1. `FormRequest` valida e normalizza l'input HTTP
2. il controller costruisce o riceve un DTO esplicito
3. il service esegue la logica applicativa
4. il controller traduce l'esito in response o redirect

## Perche'

- riduce logica sparsa nel controller
- rende il contratto dei dati esplicito
- migliora testabilita' del service
- facilita riuso in job, command o listener

## Evitare

- leggere `$request->input()` in piu' punti della stessa action
- passare array anonimi non documentati al service
- infilare query, validazione e side effects nello stesso metodo controller
