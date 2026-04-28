# Regola: Frontend Testability Contracts

Il frontend deve offrire un contratto stabile ai test E2E.

## Regole

- elementi interattivi accessibili e raggiungibili con locator semantici
- loading, empty e error state distinguibili
- nessuna dipendenza da testo volatile se basta un ruolo o un test id stabile
- selettori di test intenzionali per componenti critici quando l'accessibilita' non basta
- niente spinner permanenti o overlay che bloccano senza segnale chiaro

## Obiettivo

Ridurre falsi negativi e rendere i test piu' robusti a refactor cosmetici.
