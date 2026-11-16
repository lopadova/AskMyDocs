# Conoscenza per progetto

Una specifica attiva trasforma i passaggi originali in contributi automatici a entità separate per azienda, progetto, tipo, namespace e identificativo. Gli identificativi restano stringhe: `00123` e `123` sono distinti. Nomi simili non causano fusioni. I progetti senza attivazione esplicita mantengono AutoWiki precedente. I nuovi strumenti nel catalogo agente sono offerti ai progetti attivati.

## Calibrazione corretta del 16 novembre 2026

Il giudizio `knowledge-admission-v2` separa il soggetto verificato dal codice, l’affermazione da valutare e il passaggio originale. Il vecchio giudizio chiedeva un identificativo letterale anche per le procedure con identità assegnata dalla fonte; confondeva inoltre la conservazione di una proposta con la conferma della sua efficacia e le istruzioni documentate con un’esecuzione osservata. Le domande ora distinguono attribuzione, fedeltà dell’intera affermazione e categoria del supporto. Una proposta resta proposta; un conflitto resta irrisolto; uno stato chiuso non prova una riparazione.

La soglia **0,70** è stata fissata sui soli casi di calibrazione prima di misurare la validazione. Fra le soglie della griglia con zero ammissioni errate, si sceglie quella con il massimo richiamo e, a parità di richiamo, la più alta. Non sono state cambiate domande o soglia dopo la validazione.

| Gruppo | Casi | Positivi ammessi | Negativi ammessi | Richiamo |
|---|---:|---:|---:|---:|
| Calibrazione | 17 | 7 / 8 | 0 / 9 | 87,5% |
| Validazione | 24 | 9 / 10 | 0 / 14 | 90% |
| Totale | 41 | 16 / 18 | 0 / 23 | 88,9% |

Sui 17 esempi originali vengono ora ammessi tutti i 9 positivi, contro 2 nella prova precedente. I 24 esempi aggiunti coprono relazioni esplicite e dedotte dalla vicinanza, attributi inventati, fatti scambiati fra due ordini, proposte promosse a successi, conflitti risolti senza prova, istruzioni incomplete e un tentativo di iniettare istruzioni nella fonte. Restano due astensioni corrette dal punto di vista operativo, ma falsi negativi semantici: tentativi senza successo la cui classificazione `proposed` ha confidenza insufficiente. La soglia non viene abbassata per recuperarli.

Sono state effettuate **36 richieste Jev**, senza errori del provider; cinque casi sono respinti dal codice senza chiamata. Costo noto aggiuntivo **0,001419978 USD**, latenza cumulativa **18,49 secondi**. Ogni risposta a pagamento è conservata nel [rapporto calibrato](knowledge-evaluation-2026-11-16-calibrated.json); le misure iniziali non sono state sovrascritte o richiamate per ricomporre il rapporto. Le etichette attese non vengono inviate al modello.

La calibrazione include versione del giudizio e snapshot `typesafe/jev-1.13-20260917`, fissato soltanto per questo flusso. Una calibrazione precedente senza versione, oppure una risposta da un modello diverso, produce astensione. `sections-v2` separa i nuovi checkpoint dai giudizi precedenti. Nessuna specifica aziendale è stata attivata: zero falsi positivi su questi esempi sintetici è una misura di regressione, non una garanzia sui dati aziendali.

Verifiche della correzione: **257 test PHP passati** (1114 asserzioni) nelle suite `tests/Unit/Decisions` e `tests/Feature/Kb/Knowledge`; cinque test React della conoscenza e controllo TypeScript passati. Dopo l’ultima modifica al salvataggio atomico sono stati ripetuti i cinque test del replay (22 asserzioni), tutti passati. Il replay con punteggi salvati deliberatamente alterati ricostruisce comunque 16 ammissioni corrette e zero ammissioni errate dalle risposte originali; input cambiati vengono rifiutati senza chiamate.

Il comando distingue rifiuti deterministici, astensioni ed errori del provider. In assenza di una soglia valida mostra le metriche alla soglia dichiarata di misurazione, senza ricorrere implicitamente a 1,0. È possibile ricalcolare il rapporto senza costi con:

```bash
php artisan kb:knowledge-evaluate --replay=docs/knowledge-evaluation-2026-11-16-calibrated.json --output=/tmp/knowledge-replayed.json
```

Il replay verifica dataset, input di ogni caso, versione del giudizio e modello; ricalcola i punteggi dalle risposte conservate. Per una nuova misura usare `--live --output=<nuovo-file>`: il comando richiede il file di output, salva ogni caso immediatamente con sostituzione atomica del checkpoint e non sovrascrive una misura esistente.

## Prova iniziale del 16 novembre 2026

OpenRouter è nuovamente disponibile: la prova diretta e le 14 richieste Jev necessarie ai 17 esempi etichettati sono riuscite, senza errori del provider. Tre esempi sono stati fermati dai controlli deterministici prima della chiamata. Il modello restituito è `typesafe/jev-1.13-20260917`.

La calibrazione resta **non utilizzabile**. Alla soglia di misurazione 0,500001 si osservano 2 ammissioni corrette, 12 astensioni e 3 rifiuti deterministici. Nessuna ammissione errata, ma soltanto 2 dei 9 casi positivi sono ammessi. Alla soglia candidata minima 0,51 il richiamo dei positivi è 20% sul gruppo di calibrazione e 25% su quello di validazione: entrambi sotto il requisito del 75%. Nessuna soglia è stata proposta o attivata.

Costo noto complessivo, inclusa la prova diretta: **0,000460026 USD**; latenza cumulativa delle richieste circa **7,57 secondi**. Le metriche aggregate del comando usano la soglia di ripiego 1,0 quando non trovano una calibrazione: il rapporto distingue questi valori dagli esiti effettivi della misurazione e riporta l’analisi delle soglie senza ripetere chiamate a pagamento.

Il [rapporto aggiornato](knowledge-evaluation-2026-11-16.json) conserva risposte, probabilità e consumi. Il precedente blocco HTTP 402 è risolto; occorre migliorare e rivalutare il giudizio semantico prima dell’attivazione. Nessuna impostazione aziendale o di progetto è stata cambiata. Le verifiche nelle sezioni successive documentano il collaudo dell’11 novembre.

## Contratto e attivazione

L'editor si trova in **Auto-Wiki Settings**. La descrizione italiana può generare una bozza; i campi strutturati permettono di modificare tipi, proprietà, relazioni, namespace, strategie, regole, destinazioni e calibrazione. La modalità JSON avanzata conserva la modifica precisa del contratto. `null` nel progetto eredita l'intero blocco aziendale. Una revisione salvata è immutabile; correggerla crea una revisione nuova. I blocchi sono `types` (tipi/proprietà/relazioni), `identity` (namespace, espressioni e riconoscimento), `admission` (regole e calibrazione), `contributions` (destinazioni).

Il namespace `procedure` iniziale usa la strategia `source`: una risposta completa può creare una procedura senza richiedere tre documenti o inventare un codice aziendale. Il suo identificativo tecnico deriva dal percorso della fonte. Per riunire fonti diverse intorno a una procedura identificata, configurare un namespace `explicit` e il relativo identificativo presente negli originali.

La prova usa al massimo tre sezioni per ciascuna delle cinque fonti selezionate; la risposta dichiara le sezioni non elaborate. La prova non pubblica entità. Attivare non rielabora lo storico. Selezionare progetto, documenti, tipi di fonte o prefisso e avviare la rielaborazione in blocchi con cursore.

La pubblicazione semantica richiede `admission.calibration = {"threshold": <soglia misurata>, "reference": "<riferimento al rapporto>", "judge_version": "knowledge-admission-v2", "model": "typesafe/jev-1.13-20260917"}`. Senza calibrazione, oppure con provider indisponibile/giudizio incerto, i contributi si astengono e gli originali restano nella ricerca documentale. Non usare una soglia arbitraria.

## Elaborazione e operatività

`DocumentIngestor::persistDocumentAndChunks` registra il dispatch dopo il commit, comune a caricamenti, importazioni sincrone, Flow, connettori e reingest. Documento, chunk e ACL sono persistiti prima dell'elaborazione. Per una specifica attiva si scrivono soltanto artefatti automatici separati: nessuna modifica ai documenti umani. Le fonti di triage sono lette dagli originali con hash verificato; le schede Freshdesk usano la verifica della lineage originale; i concetti automatici non sono nuove prove.

Ogni elaborazione conserva contratto effettivo e hash, versione originale e versione estrattore. Le sezioni coprono tutto il testo indicizzato disponibile con sovrapposizione. Estratti e giudizi sono checkpoint persistenti; un retry riusa ciò che è già stato acquisito. Un lock e i vincoli unici impediscono pubblicazioni duplicate. Prima della pubblicazione si ricontrollano fonte e revisione sotto lock; l'attivazione usa gli stessi lock delle specifiche.

Il job usa la connessione Redis `knowledge`, coda `knowledge`, lease 660 secondi, timeout 540 secondi. Avviare il worker corrispondente, ad esempio:

```bash
php artisan queue:work knowledge --queue=knowledge --timeout=540
```

`KB_KNOWLEDGE_ENABLED=false`, il gate AutoWiki e **Ferma arricchimento** interrompono il nuovo arricchimento. Le prove già pubblicate restano consultabili se la fonte è ancora valida e accessibile. Una nuova specifica marca le precedenti come da rielaborare, senza cancellarle né rianalizzare tutto automaticamente.

Il registro viene scritto prima della consegna in coda: una coda indisponibile lascia una richiesta pendente visibile e rielaborabile. Le elaborazioni espongono copertura, astensioni, errori, date/ritardo, contributi accettati e non confermati, latenza e costo noto delle decisioni. I token dell'estrazione sono registrati nei checkpoint e le chiamate del provider mantengono la misurazione FinOps esistente. Un costo sconosciuto non va interpretato come gratuito.

## Lettura, sicurezza e grafo

La ricerca comune aggiunge passaggi originali delle entità alla ricerca documentale. L'agente dispone di `search_knowledge_entities` e `read_knowledge_context`; quest'ultimo legge timeline paginate oppure documenti usando mappa e cursore delle sezioni. Le citazioni esistenti restano compatibili. Gli estratti del planner dichiarano troncamenti e copertura parziale. Il percorso investigativo conserva finestre distinte dello stesso documento entro i budget esistenti.

Ogni lettura ricontrolla tenant/progetto, permessi dell'attore, stato/versione della fonte, hash della sezione e appartenenza letterale della citazione. Revoca, modifica o cancellazione invalidano subito l'utilizzo delle vecchie prove. Etichette, relazioni e riepiloghi vengono costruiti soltanto dai contributi autorizzati. Il riepilogo è una proiezione dei fatti supportati della pagina corrente, non un testo persistente che mescola fonti nascoste. Le quote ripetute hanno una chiave di supporto comune e non diventano conferme indipendenti.

I nodi e gli archi `entity` sono distinti dalle proiezioni documentali. I lettori legacy non li enumerano; rebuild e lint documentali lavorano sulla categoria documentale. La proiezione entità mantiene una verifica strutturale separata; le relazioni visibili provengono dalle osservazioni autorizzate, mai dagli archi grezzi.

## Contratti

- `/api/admin/kb/knowledge-specifications`: versioni, `drafts`, `generate`, `{revision}/preview`, `{revision}/activate`, `disable`, `reprocess`, `runs`. Ruoli amministrativi e tenant esistenti.
- `/api/kb/knowledge/entities`: ricerca con `project_key`, `q`, `namespace`; `/{id}` e `/{id}/timeline` con `cursor`.
- `/api/kb/knowledge/documents/{id}/context`: `project_key`, `cursor` di sezione, `limit` fino a tre.
- MCP: `search_knowledge_entities`, `read_knowledge_context`, scope `mcp:read`; amministrazione con `manage_knowledge_specification` e scope `mcp:tools:write` (non disponibile alle connessioni OAuth read/ingest). Una connessione OAuth di progetto fissa lo scope del progetto.
- CLI: `kb:knowledge <operazione> <progetto> --tenant=... --actor=...`, operazioni `template`, `versions`, `draft`, `generate`, `activate`, `disable`, `preview`, `reprocess`, `runs`, `search`, `read`. Richiede un utente autorizzato; le operazioni amministrative richiedono ruolo admin/super-admin.
- `kb:knowledge-evaluate --live --output=<file>` misura le associazioni su esempi sintetici etichettati e propone una soglia solo se calibrazione e validazione separata soddisfano i criteri. Senza `--live` o `--replay` mostra soltanto gli esempi; `--replay=<rapporto>` ricalcola offline gli esiti salvati. Serve una successiva validazione sugli esempi dell'azienda.

## Matrice di completamento e verifiche

Verifiche eseguite l’11 novembre 2026: 383 test Laravel pertinenti (2397 asserzioni), 3 test del contratto Jev (10 asserzioni), 22 test React, typecheck e build frontend/widget, 3 E2E reali su Herd. I test con estrattore/giudizi simulati verificano contratti e persistenza, non la qualità semantica del provider reale.

| Requisito | Copertura | Stato verifica |
|---|---|---|
| Identità per progetto/namespace, zeri iniziali, due ordini, procedura da una risposta | `KnowledgeAcceptanceTest` (23 scenari nella verifica finale) | Eseguita, passa |
| Versioni, ereditarietà, attivazione senza reprocessing | `KnowledgeAcceptanceTest`, editor React | Eseguita, passa |
| Originali, sezioni lontane, lettura mirata | `KnowledgeAcceptanceTest`, `KbSourceReaderTest`, `KbInvestigationServiceTest` | Eseguita, passa |
| Retry/checkpoint e kill switch | `KnowledgeAcceptanceTest` | Eseguita, passa |
| Modifica, cancellazione, revoca ACL | `KnowledgeAcceptanceTest` | Eseguita, passa |
| API, CLI, MCP sul servizio comune | `KnowledgeAcceptanceTest` | Eseguita, passa |
| Ingestione queue/sync e compatibilità legacy | `IngestDocumentJobTest`, `DocumentIngestorTest`, Flow documento/cartella, accettazione sync | Eseguita, passa |
| Ricerca sync/streaming/ondate e ripresa sicura | controller messaggi/SSE/KB, `AgentWaveKnowledgeSearchTest`, `ExactKnowledgeWaveIntegrationTest`, accettazione strumenti/ripresa | Eseguita, passa; provider di risposta simulati |
| Rebuild/lint categorie | `RebuildGraphFlowTest`, AutoWiki, accettazione con documento canonico reale | Eseguita, passa |
| React editor/ricerca, localizzazione, typecheck/build | Vitest, TypeScript, Vite | Eseguita, passa |
| E2E editor/ricerca, mobile e temi | Playwright: salvataggio/attivazione, ordine e fonti, cancellazione fonte; tastiera, 390 px, chiaro/scuro | 3 eseguiti, passano; estrazioni sintetiche precompilate, nessuna qualità IA dedotta dagli E2E |
| Jev reale, falsi positivi, astensioni, tempi/costi | 17 esempi con split calibrazione/validazione | Eseguita: provider HTTP 402, nessuna calibrazione valida |
| Migrazione additiva PostgreSQL e rollback SQLite | migrazione locale/test | Forward PostgreSQL locale eseguita; rollback/forward SQLite eseguiti e passano |


## Eseguire nuovamente le verifiche

```bash
php artisan test --compact tests/Feature/Kb/Knowledge tests/Unit/Decisions/KnowledgeAdmissionTest.php
npm test -- frontend/src/features/knowledge
npm run typecheck
npm run build
php scripts/dev/knowledge-fixture.php --create --file=/tmp/knowledge-fixture.json
KNOWLEDGE_E2E_FIXTURE=/tmp/knowledge-fixture.json npx playwright test --config=playwright.knowledge.config.ts
php scripts/dev/knowledge-fixture.php --cleanup --file=/tmp/knowledge-fixture.json
```

Gli E2E locali non resettano il database. Il file fixture contiene un utente temporaneo autorizzato, `tenant`, `team_hash` e `document_ids`; il progetto è `orders`, con messaggi sintetici dell'ordine `00123`. L'ultimo E2E cancella soltanto la prima fonte sintetica del tenant indicato. Usare esclusivamente una fixture dedicata. Il comando fixture è disponibile soltanto in ambiente local/testing, scrive credenziali con permessi 0600 e non chiama provider IA. I dati temporanei usati durante questa implementazione sono stati rimossi.

Il rapporto misurato del provider è in `docs/knowledge-evaluation-2026-11-11.json`: nessuna soglia è stata attivata, perché OpenRouter ha risposto HTTP 402. La suite deterministica verifica separatamente risposta tipata, conservazione del costo, incertezza e astensione su 402.


La suite architetturale completa ha 34 test passati e un errore preesistente: `GraphExecutorNotAdoptedTest` rileva `FlowNodeHandler` in `app/ApiTools/OperationNode.php`, già presente in `HEAD` prima di questo lavoro. Il controllo R30 dei nuovi modelli passa. Nessuna modifica a quel sottosistema è stata inclusa.

La suite completa dell’intera repository non è stata eseguita: sono state eseguite le suite pertinenti elencate sopra. Il collaudo iniziale dell’11 novembre era bloccato da HTTP 402; la correzione e le misure Jev del 16 novembre sono documentate sopra. L’estrazione generativa su fonti aziendali non è coperta dalle fixture sintetiche del giudizio. Per attivare su dati aziendali occorrono valutazione e calibrazione su esempi aziendali, revisione attiva e worker `knowledge`. Nessun progetto aziendale è stato attivato automaticamente.


Commit locali dell'implementazione: `b198d1add` (servizi, persistenza e contratti), `b907271ea` (protezione dalle conferme da prosa generata), `c89d73c02` (editor strutturato, esplorazione e test UI). Nessun push o PR. Le modifiche desktop presenti all'inizio sono state escluse.
