# Regola: Analisi Fallimenti Test in CI

Prima di proporre un fix a un fallimento CI:

- leggere il log completo del job
- scaricare e aprire artifact rilevanti
- correlare trace, screenshot, console errors e backend/app logs se disponibili
- classificare il problema

## Anti-pattern

- proporre fix dal solo messaggio finale della CI
- ignorare artifact o log allegati
- confondere un problema di ambiente con un bug applicativo
