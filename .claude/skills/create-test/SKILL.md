---
name: create-test
description: Aggiungere test per nuovo codice Laravel 13.x + PHP 8.3+ — unit test per logica pura/service, feature test per HTTP/auth/validation/persistence, browser/E2E solo per journey critici. Continua col framework già scelto dal repo (PHPUnit per AskMyDocs). Trigger quando l'utente chiede di scrivere test per una nuova feature/service/controller, quando si tocca codice senza coverage, o quando si verifica un fix con regression test.
---

# Create Test

Per nuovo codice Laravel:

- baseline target: Laravel 13.x, PHP 8.3+

- unit test per logica pura o service
- feature test per HTTP, auth, validation e persistence
- browser/E2E solo per journey critici

## Framework test

- se il repo usa gia' PHPUnit, continua con PHPUnit
- se il repo e' nato con Pest, continua con Pest
- non convertire un codebase da PHPUnit a Pest dentro un task funzionale

## Regole

- testare comportamento, non implementazione interna
- factory e fixture leggibili
- un test deve spiegare il caso che protegge
