# Create API Endpoint

Skill per creare endpoint API moderni in Laravel 13.

## Pipeline consigliata

1. route
2. controller sottile
3. FormRequest
4. DTO
5. service/action
6. JsonResource o ResourceCollection
7. feature test HTTP

## Regole

- validazione in `FormRequest`
- nessuna logica di business nel controller
- usare `JsonResource` per shaping stabile della response
- status code coerenti
- error handling prevedibile

## Quando usare Resource

- singolo record: `JsonResource`
- lista paginata o collezione: `ResourceCollection` o resource collection dedicata
