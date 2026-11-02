# Dossier di avanzamento — 2 novembre 2026

La preparazione della fase 0 è consegnabile. La validazione sull'ambiente di
sviluppo e le fasi 1–6 restano da eseguire, rispettando il gate della baseline.

## Codice e perimetro

- Branch: `codex/feature-operational-consolidation`.
- Base `develop`: `7852a14f22395d54863fdd1530985bb07cd0d3c9`.
- Commit della strumentazione: `30251bb8a`.
- Due tabelle QA additive, attivazione inizialmente disabilitata, vincolo esplicito
  all'ambiente development, campagna appartenente a tenant/progetto/attore.
- Prenotazione condivisa prima del dispatch, contabilità idempotente, arresto su
  addebito ignoto, modello non ammesso, prenotazione insufficiente o scaduta.
- Copertura dei trasporti applicativi HTTP/SDK, embedding, Jev e processi figli;
  revisione fotografata sulla run e rifiuto di worker con revisione diversa.
- Export tecnico, fonte del costo, durata della chiamata e classi chiamanti senza
  argomenti, hash del payload; nessun nuovo archivio di testi cliente nel ledger.
- Client e preparatore del corpus con file privati, manifesto a tariffe verificate
  tramite sole letture del catalogo e rubrica `qa-rubric-v1`.

Le politiche della pipeline non sono state consolidate anticipatamente: modelli,
soglie, limiti e configurazioni degli ambienti non sono stati modificati. Nessun
push, PR o deploy è stato effettuato.

## Verifiche locali

| Verifica | Esito | Limite dell'evidenza |
|---|---|---|
| Suite Agent/Chat/Unit Agent/OpenRouter/Architecture | 644 test, 643 superati, 1 fallimento preesistente | Eseguita prima dell'ultima aggiunta dei dettagli di telemetria |
| Suite mirata dopo telemetria: QA, Chat, tenant scope, SDK OpenRouter | 135 test, 582 asserzioni, superati | HTTP provider simulato; nessuna inferenza |
| QA dopo obbligatorietà della revisione sulla run | 20 test, 84 asserzioni, superati | Include due processi reali sullo stesso SQLite |
| Client HTTP e preparazione del corpus/manifesto | 14 test superati | Fixture locali; tariffe reali non ancora congelate per questa campagna |
| Sintassi PHP e controllo whitespace del diff | Superati | Non sostituiscono il collaudo di sviluppo |

Il fallimento esteso è
`GraphExecutorNotAdoptedTest::test_the_host_does_not_invoke_the_graph_executor`:
rileva `OperationNode.php -> FlowNodeHandler`. Test e file applicativo sono
identici a `origin/develop`; non è stato indebolito il controllo. La questione di
tenancy dei dati Flow segnalata dal test rimane una verifica separata da risolvere
prima di attestare una CI complessivamente verde.

Comando della suite estesa:

```sh
php vendor/bin/phpunit tests/Feature/Agent tests/Feature/Chat tests/Unit/Agent \
  tests/Unit/Ai/OpenRouterProviderTest.php tests/Architecture --no-progress
```

I test di prezzo distinguono valori mancanti da zero esplicito; quelli di costo
verificano che una connessione incerta non consenta ulteriori tentativi e che una
callback ripetuta non conteggi un nuovo addebito. Il test concorrente sincronizza
due processi prima di due prenotazioni incompatibili con lo stesso saldo: una è
ammessa e una bloccata. La prova PostgreSQL su sviluppo rimane da svolgere dopo
il primo deploy.

## Preparazione privata e attività live

Sono state effettuate soltanto letture delle cronologie e delle fonti già
autorizzate sul progetto `autry`. Nessuna nuova domanda è stata inviata alla chat.

- Dieci prime domande congelate, con hash individuali, in file 0600 esclusi da Git.
- Indice del corpus: `3c9a72978d54640a8ede758202b85a7477afb329fcf163b7983911ed610d5626`.
- Sedici riscontri delle fonti storiche archiviati con sole GET, tutti disponibili.
- Schede di valutazione separate dagli input: **da completare sulle fonti
  originali**, senza assumere corrette le risposte storiche dell'assistente.
- Campagna di sviluppo: non ancora creata; manifesto effettivo da completare
  dopo la fotografia della configurazione distribuita.
- Nuova baseline: 0/10 dialoghi, 0/30 turni.
- Candidato: 0/10 dialoghi, 0/30 turni.
- Spesa AI di questo ciclo: **0 USD**; budget autorizzato complessivo: **10 USD**.

Gli snapshot storici delle fonti non dimostrano che quei record siano immutati
oggi: durante i due round confrontare versioni/date e annotare eventuali variazioni
delle fonti, separandole dalle regressioni del codice.

## Passaggio operativo

Il [runbook](../runbooks/operational-consolidation.md) contiene migrazione,
configurazione development, revisione di web/worker, creazione dell'unica campagna,
comandi del client, arresto e conservazione del ledger. Marco esegue il primo
deploy; seguono verifica concorrente sul database distribuito e baseline 10×3.
Solo dopo l'archiviazione della baseline si iniziano i quattro contratti interni
e gli altri interventi del piano. Il confronto finale e l'aggiornamento della
relazione PDF/Word richiedono i risultati del candidato e non sono ancora attestati.
