# Domain Scaffold

Scaffold concettuale per un nuovo dominio/modulo Laravel.

Target: Laravel 13.x, PHP 8.3+.

## Struttura consigliata

```
app/Domain/{Domain}/
  Actions|Services/
  Dto/
  Http/Controllers/
  Http/Requests/
  Jobs/
  Models/   # opzionale, solo se il modulo li possiede
  Policies/
  Routes/
```

## Checklist

- definisci il confine del dominio
- evita di aprire un nuovo dominio per una sola funzione banale
- decidi contratti HTTP, servizi, jobs e test prima di generare file in massa
