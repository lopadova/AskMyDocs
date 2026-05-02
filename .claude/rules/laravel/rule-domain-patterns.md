# Regola: Pattern Strutturali Domini Laravel

Struttura consigliata per moduli business significativi:

- `Dto/` per input espliciti
- `Http/Requests/` per validazione HTTP
- `Services/` o `Actions/` per workflow
- `Jobs/` per lavoro asincrono
- `Routes/` se il modulo espone endpoint propri

## Regole

- nomi espliciti per classi e cartelle
- niente servizi generici onnivori
- evitare dipendenze cicliche tra domini
- se il progetto mostra un pattern dominante Service + DTO + Request, seguirlo in modo coerente
