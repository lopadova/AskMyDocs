# Playwright Stack Overlay

Queste istruzioni si applicano a test E2E e browser automation.

## Principi

- esegui sempre lo scope piu' stretto utile
- non classificare un fallimento senza leggere trace, screenshot e log disponibili
- differenzia bug del test, bug applicativo e problema d'ambiente
- non rendere la suite fragile con wait arbitrarie o locator deboli
- proteggi PII, credenziali e ambienti reali
