# Dossier di avanzamento — 3 novembre 2026

La preparazione della fase 0 è consegnabile. La validazione sull'ambiente di
sviluppo e le fasi 1–6 restano da eseguire, rispettando il gate della baseline.

## Codice e perimetro

- Strumentazione integrata in `develop`: `01092f5122ceb18467cf01d5ffbfcd189d13cff9`.
- Commit funzionali: `30251bb8a`, `17f3a4d6c`, `83f93df0a`.
- Nuovo branch locale per il seguito: `codex/feature-operational-consolidation-pipeline`,
  dalla revisione integrata; le modifiche alla pipeline attendono la baseline.
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
soglie e limiti restano quelli rilevati. Marco ha integrato e distribuito la
strumentazione; nessun push o PR è stato eseguito dall'agente. Il 3 novembre
Marco ha autorizzato l'agente ad applicare le tre variabili QA e il relativo
deploy di configurazione sul solo ambiente di sviluppo.

## Verifiche locali

| Verifica | Esito | Limite dell'evidenza |
|---|---|---|
| Suite Agent/Chat/Unit Agent/OpenRouter/Architecture | 645 test, 644 superati, 1 fallimento preesistente; 3589 asserzioni | Prima dell'ultima aggiunta opzionale dei metadati del catalogo |
| QA dopo metadati degli alias e probe applicativo | 21 test, 96 asserzioni, superati | HTTP provider simulato; due processi applicativi reali sullo stesso SQLite |
| Client HTTP e preparazione del corpus/manifesto | 16 test superati | Fixture locali; non attestano inferenze live |
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
- Schede di valutazione separate dagli input: 55 fatti con riscontro letterale
  nei file originali e 12 attese ERP; le risposte storiche non sono l'oracolo.
- Campagna di sviluppo: non ancora creata; manifesto verificato con sole GET
  del catalogo pubblico e modelli invariati, in attesa del gate distribuito.
- Nuova baseline: 0/10 dialoghi, 0/30 turni.
- Candidato: 0/10 dialoghi, 0/30 turni.
- Spesa AI di questo ciclo: **0 USD**; budget autorizzato complessivo: **10 USD**.

Gli snapshot storici delle fonti non dimostrano che quei record siano immutati
oggi: durante i due round confrontare versioni/date e annotare eventuali variazioni
delle fonti, separandole dalle regressioni del codice.

## Passaggio operativo

Il [runbook](../runbooks/operational-consolidation.md) contiene migrazione,
configurazione development, revisione di web/worker, creazione dell'unica campagna,
comandi del client, arresto e conservazione del ledger. Il primo deploy del codice
è attivo; seguono configurazione QA, verifica concorrente sul database distribuito
e baseline 10×3.
Solo dopo l'archiviazione della baseline si iniziano i quattro contratti interni
e gli altri interventi del piano. Il confronto finale e l'aggiornamento della
relazione PDF/Word richiedono i risultati del candidato e non sono ancora attestati.

## Aggiornamento del 3 novembre: preparazione del gate live

Il controllo su Laravel Cloud e il preflight applicativo del 02/11 alle
23:06 UTC hanno confermato il deploy `7852a14`, precedente alla strumentazione:
classe del ledger e tabelle QA assenti, `APP_RELEASE` nullo. Il token esistente
è valido e ha accesso diagnostico; non è stato creato o ampliato alcun accesso.
La configurazione effettiva di `autry` è archiviata privatamente. Quel controllo
precede il primo deploy e non descrive lo stato distribuito successivo.

La preparazione indipendente degli esiti ora comprende:

- 34 preview complete, tutte non canoniche, ricostruite dai chunk tramite API;
- confronto indipendente dei 34 file originali nello storage: tutti i 55 estratti
  selezionati corrispondono dopo normalizzazione degli spazi; un PDF è stato
  verificato tramite estrazione del testo, conservando l'hash dei byte originali;
- 55 fatti con quote letterali, riferimenti e hash del contenuto;
- 12 attese su record ERP, separate dai fatti delle email e dagli stati di reso;
- distinzione tra `charge` dell'ordine e prova di un rimborso;
- aggiornamenti temporali, negazioni e richieste registrati separatamente;
- un caso senza riscontro ERP storico, da verificare sul nuovo recupero live.

Le schede in `oracle-review.json` sono state riesaminate rispetto agli originali,
senza usare le risposte storiche come verità. Hash della versione delle attese:
`86fddfc9766b52737952140e36400139da80e1666cc6c6593746ce7f169b6a2d`.
Questa è una revisione delle fonti archiviate, non una baseline eseguita e non
una prova di assenza di documenti ulteriori.

Il catalogo reale ha evidenziato fasce di prezzo condizionali. Il commit
`17f3a4d6c` prenota il massimo tra endpoint, fasce di contesto, finestre orarie e
cache write, senza sconti presunti; verifica gli identificativi canonici nel
catalogo e disabilita la ricerca web implicita. I prezzi dei quattro modelli
effettivi sono stati letti con sole GET. Un errore DNS temporaneo ha impedito
un primo tentativo, conservato come incompleto. La lettura successiva del
03/11 alle 00:00 UTC ha verificato prezzi e identificativi canonici e prodotto
il manifesto privato con hash
`c1df069f547ad2c41cbe940252c207bc0699611c3495cd3f4803faf443bab445`.
Nessun dato mancante è stato sostituito con un prezzo di default o con un modello
differente.

Il nuovo `qa:campaign probe` rende eseguibile G0b sul database configurato:
sincronizza due processi PHP applicativi, verifica una sola prenotazione ammessa
e controlla una doppia callback. Il contesto è simulato, non possiede un attore
chat, non apre conversazioni e blocca richieste HTTP. Saldi e riferimenti di
simulazione restano distinguibili da quelli dell'unica campagna AI da 10 USD.
La prova passa localmente su SQLite; la verifica PostgreSQL attende l'attivazione
della configurazione QA.

Il deploy Cloud **183**, revisione `01092f5122ceb18467cf01d5ffbfcd189d13cff9`,
è stato verificato come attivo: classi e tabelle QA sono presenti. Il preflight
successivo al deploy rileva ancora `APP_RELEASE=null`, campagne disabilitate e
tier `production`; la creazione della campagna viene quindi correttamente
rifiutata. L'inserimento delle tre variabili richiede l'editor Cloud: quello
mascherato non accetta modifiche. La revisione automatica ha rifiutato l'azione
di rivelazione dei segreti; è stata chiesta un'autorizzazione specifica senza
aggirare il blocco o alterare la configurazione tramite la console applicativa.

Verifiche aggiornate: suite estesa di 645 test, 644 superati e lo stesso singolo
fallimento Flow già presente; QA mirata dopo l'aggiunta degli alias: 21 test e
96 asserzioni superati; 16 test Python superati. Il runbook ora segue il ciclo
gestito da Laravel Cloud per migrazioni, configurazione e ricambio dei worker.

La spesa AI resta **0/10 USD** e i nuovi turni live restano **0/60**. G0 non è
chiuso: le fasi 1–6 mantengono il loro perimetro completo e attendono la baseline
obbligatoria, senza anticipare modifiche della pipeline.
