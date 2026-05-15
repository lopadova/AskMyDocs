---
name: create-controller
description: Pattern base per introdurre un controller Laravel (web/api/admin) su Laravel 13.x + PHP 8.3+ — FormRequest per validazione non banale, DTO/command object per payload ricco, delega a service/action, return view/resource/redirect coerente, almeno un feature test. Trigger quando l'utente chiede di creare un nuovo controller in app/Http/Controllers/, una nuova rotta che ha bisogno di un handler dedicato, o quando si scaffolda un nuovo entry-point HTTP.
---

# Create Controller

Pattern base per introdurre un controller Laravel.

Target: Laravel 13.x, PHP 8.3+.

## Checklist

- scegli se e' web, api o admin
- sposta validazione in Form Request se non e' banale
- se il caso d'uso ha payload ricco, costruisci un DTO o command object esplicito
- delega il lavoro a un service/action
- restituisci view, resource o redirect coerente
- scrivi almeno un feature test per il comportamento principale
