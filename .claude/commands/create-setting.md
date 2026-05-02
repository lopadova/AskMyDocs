# Create Setting

Pattern generale per introdurre un setting persistito in un progetto Laravel.

## Passi

1. Definisci il bisogno: feature flag, limite, parametro o endpoint esterno.
2. Scegli dove vive:
   - config statica se non deve cambiare a runtime
   - tabella settings se deve essere modificabile da admin o ambiente
3. Dai una chiave stabile e leggibile.
4. Crea migration o seed iniziale.
5. Leggi il valore tramite un service o repository dedicato, non sparso ovunque.

## Regole

- niente chiavi ambigue
- default esplicito
- descrizione chiara se esiste un pannello admin
- non hardcodare limiti che devono cambiare nel tempo
