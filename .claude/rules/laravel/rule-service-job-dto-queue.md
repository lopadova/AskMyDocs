# Regola: Pattern Service + Job + DTO

Usa questo pattern per operazioni lunghe o asincrone.

## Struttura

- DTO/value object per i dati di input
- service/action per la logica
- job per l'esecuzione in coda

## Regole

- il job orchestra retry, timeout e contesto di esecuzione
- la logica riusabile vive nel service, non nel job
- il DTO evita array anonimi non tipizzati tra boundary
