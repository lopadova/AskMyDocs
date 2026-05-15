---
name: create-service
description: Creare un service o action object Laravel (Laravel 13.x + PHP 8.3+) con input esplicito (parametri o DTO), una responsabilità chiara, dipendenze iniettate e output deterministico. Trigger quando l'utente chiede di estrarre logica da un controller, di creare una nuova classe in app/Services/ o app/Actions/, o quando un controller cresce oltre la pura orchestrazione.
---

# Create Service

Pattern per creare un service o action object in Laravel.

Target: Laravel 13.x, PHP 8.3+.

## Struttura minima

- input esplicito tramite parametri o DTO
- una responsabilita' chiara
- dipendenze iniettate
- output deterministico o result object semplice

## Quando preferire un DTO

- input con molti campi
- validazione/coerenza tra campi
- uso del medesimo payload in piu' layer
- code path sync e async che condividono lo stesso contratto

## Quando spezzarlo

- se tocca piu' aggregate indipendenti
- se contiene piu' di un ramo di business maggiore
- se ha codice asincrono e sincrono mescolati
