# Create Job

Pattern per introdurre un Job in Laravel 13.

## Quando usarlo

- operazione lunga
- integrazione esterna lenta
- export/import
- side effects non necessari nella request web

## Regole

- il Job deve essere sottile
- la logica vive nel service o action dedicata
- il payload del Job deve essere esplicito e stabile
- usare DTO o identificativi chiari invece di array anonimi

## Struttura consigliata

1. Request/FormRequest o trigger applicativo
2. DTO
3. Service
4. Job che invoca il Service
5. Test del Service + test del dispatch

## Laravel 13

- valuta attributi PHP per timeout, tries e backoff se il team li adotta
- centralizza queue routing e policy di retry invece di disperderle
