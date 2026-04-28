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
