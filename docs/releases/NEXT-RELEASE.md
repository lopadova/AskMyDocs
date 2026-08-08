# Piano della prossima release

Stato rilevato l'8 agosto 2026:

- produzione: `main` al tag `v8.26.1`;
- integrazione: `develop`, con le feature successive a `v8.26.1`;
- `main` contiene il merge dell'hotfix `v8.26.1` non ancora presente nella
  cronologia di `develop`;
- il connettore API è preparato su `feature/api-connector-tools` sopra
  `develop`, ma deve ancora entrare tramite PR;
- la versione della prossima release non è ancora stata scelta.

## Sequenza proposta

1. Aprire e completare il riallineamento `main -> develop` per acquisire
   l'hotfix `v8.26.1` prima di congelare la release.
2. Ribasare i branch feature sul `develop` riallineato e aprire le rispettive PR
   verso `develop`, incluso `feature/api-connector-tools`.
3. Verificare la suite completa sul risultato integrato e chiudere lo scope.
4. Stabilire il numero SemVer della release.
5. Creare `release/X.Y.Z` dal tip approvato di `develop`.
6. Sul branch release accettare solo fix di stabilizzazione, aggiornamento
   versione, changelog e documentazione.
7. Aprire la PR `release/X.Y.Z -> main`.
8. Dopo il merge, taggare il nuovo tip di `main` con `vX.Y.Z`.
9. Aprire la PR di riallineamento `release/X.Y.Z -> develop` (o
   `main -> develop`) per non perdere i fix introdotti durante la
   stabilizzazione.

Non servono PR separate dei singoli branch feature verso `release/X.Y.Z`: quei
branch confluiscono una volta in `develop`, che diventa la base atomica della
release.

## Gate specifico del connettore API

Prima del tag occorre rendere riproducibile la dipendenza
`padosoft/askmydocs-connector-api`: pubblicare o taggare il package e sostituire
nel progetto il repository `path` assoluto e il vincolo non limitato `@dev` con
una sorgente e una versione utilizzabili in CI e in produzione.

## Criterio per creare il branch release

Il branch non viene creato durante la preparazione di questo documento perché
mancano ancora tre condizioni: riallineamento dell'hotfix, merge delle feature
approvate e scelta della versione. Crearlo prima produrrebbe un branch di
integrazione parallelo a `develop`, contrario al flusso definito in
[`docs/GITFLOW.md`](../GITFLOW.md).

