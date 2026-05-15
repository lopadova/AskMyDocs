---
name: create-api-endpoint
description: Creare endpoint API moderni in Laravel 13 con la pipeline route → controller thin → FormRequest → DTO → service/action → JsonResource/ResourceCollection → feature test HTTP. Trigger quando l'utente chiede di aggiungere una nuova rotta API in routes/api.php, un nuovo controller in app/Http/Controllers/Api/, o quando si espone una nuova capability come endpoint REST/JSON.
---

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
