# AskMyDocs Enterprise — baseline E00.01

Data della rilevazione: 27 settembre 2026  
Repository: AskMyDocs  
Branch: `main`  
HEAD rilevato: `96848ed95dc6f7169fc4ae7bf117158f0c322d4d`  
Snapshot del pacchetto: `63e9916b990dc3fb76b8b84083c72a489b5926f5`

## Perimetro e autorità

Sono stati inventariati tutti i 25 file del pacchetto `AskMyDocs-Enterprise-2026-09-26`: 7 documenti principali, 19 fonti Vocentra, `TASKS.json`, `REPOSITORY-SNAPSHOTS.json`, `MANIFEST-SHA256.json` e `VERIFICA-DOCUMENTALE.json`. Il manifest conta 50 repository ecosystemici; con AskMyDocs e WeKnora il perimetro dichiarato è 52 repository. Le fonti Vocentra restano input di contratto e coordinamento: non sono un'autorizzazione a modificare il repository Vocentra, che è presidiato da un altro agente.

Il pacchetto stesso dichiara `runtime_tests_executed: false`; pertanto nessun risultato documentale pregresso è stato trasformato in PASS runtime.

## Stato corrente osservato

| Area | Evidenza | Stato E00.01 | Conseguenza |
|---|---|---|---|
| Git | `git rev-parse HEAD` = `96848ed…`; working tree inizialmente pulito | CAMBIATO rispetto allo snapshot | Tutti i finding vanno ricalcolati su HEAD |
| Delta | `git diff --stat 63e9916… HEAD` = 20 file, +1614/-59; include v8.40 Tabular Review | CAMBIATO | La baseline v8.40 va inclusa nei gap, senza confonderla con i task enterprise |
| Composer | `composer validate --no-check-publish` exit 0 | PASS locale | Valida solo il manifest, non l'installazione completa |
| Laravel bootstrap | Prima di E00.02 falliva per `RoutineManager`; dopo installazione lock + provider esplicito, `route:list` enumera le API | RISOLTO LOCALMENTE | Ripetere su install-clean/CI |
| Routines | composer.json/lock dichiarano `padosoft/laravel-routines ^1.2`, lock `v1.2.0`; vendor è stato riallineato | RISOLTO LOCALMENTE | Il tri-surface resta da verificare funzionalmente |
| DAG | `php artisan test tests/Architecture/GraphExecutorNotAdoptedTest.php --no-coverage`: 1 test, 2 assertion, PASS | CONFERMATO | E00.03 e E03.01 restano prerequisiti; il guard non autorizza ancora l'adozione |
| 2FA | `TwoFactorController` descrive stub e restituisce 501 quando disabilitato | APERTO | E01.02 è ancora reale |
| Tenant/Flow | Esistono binder, repository e compensator tenant-aware; test di isolamento presenti | PARZIALMENTE COPERTO | Serve prova cross-tenant con cache/queue/worker reali in E00.03 |
| Retrieval | Reranker/confidence e `GraphExpander` presenti; `WikiNavigator.php` non esiste | CAMBIATO/DA MAPPARE | W01/W02 devono partire dai servizi realmente presenti |
| API/web | `routes/api.php` e `routes/web.php` separano i percorsi | OSSERVATO | Non usare cookie impersonation per il contratto addon |
| Test inventory | 793 file PHP sotto `tests`, 660 file JS/TS sotto `frontend`/`playwright` | INVENTARIO | Il conteggio file non è un conteggio di suite verdi |

## Finding riconciliati

| ID pacchetto 06 | Risultato su HEAD | Stato |
|---|---|---|
| F01 DAG non isolato | Test host di non-adozione presente e PASS | ANCORA PRESENTE / protettivo |
| F02 2FA stub 501 | Stub e risposte 501 presenti | ANCORA PRESENTE |
| F03 Flow Connect dev | Non risulta nel composer.lock corrente | DA VERIFICARE IN E00.02 |
| F04 Rebel step-up | Non presente nel lock AskMyDocs corrente | DA VERIFICARE CROSS-REPO |
| F05 Flow AI identity seam | Package non presente nel lock corrente | DA VERIFICARE CROSS-REPO |
| F06 Routine delegation | Vendor ripristinato e provider registrato; default broker fail-closed presente | RISOLTO BOOTSTRAP, runtime delegation aperto |
| F07 FinOps reservation | Package presente nel lock | DA PROVARE, non verificato |
| F08 quality routing | Package presente nel lock | DA PROVARE, non verificato |
| F09 EverPrompt upstream auth | Fuori dal codice AskMyDocs e presidiato dal relativo owner | APERTO COORDINAMENTO |
| F10 Rebel admin sample | Package non presente nel lock | DA VERIFICARE CROSS-REPO |
| F11 connector base | Lock v1.6.0 presente | LIMITE CONTRATTUALE CONFERMATO, integrazione non provata |
| F12 chat stateless/web distinti | Route files presenti | CONFERMATO STATICAMENTE |
| F13 reranker/confidence euristici | Servizi presenti; nessuna valutazione live eseguita | APERTO QUALITÀ |
| F14 GraphExpander/WikiNavigator separati | GraphExpander presente, WikiNavigator assente | CAMBIATO, mapping da aggiornare |
| F15 WeKnora | Snapshot dichiarato, repository non modificato da questo task | NON VERIFICATO QUI |
| F16 Telegram/Discord outbound | Package non presente nel lock | DA VERIFICARE CROSS-REPO |
| F17 guardrails HITL | Package v1.6.0 presente | DA PROVARE IN E02.03 |
| F18 full-duplex | Package non presente nel lock | DA VERIFICARE CROSS-REPO |

## Decisioni che bloccano modifiche premature

1. Non adottare il DAG e non rimuovere `GraphExecutorNotAdoptedTest` finché E00.03 non dimostra isolamento di tenant, cache e worker e E03.01 non prova l'adozione con PostgreSQL/queue reali.
2. Non dichiarare release riproducibile finché il bootstrap Artisan non risolve la divergenza composer-lock/vendor.
3. Non implementare o modificare Vocentra: il suo agente mantiene quel perimetro. AskMyDocs espone soltanto contratti, endpoint e compatibility manifest concordati.
4. Non chiamare PASS una suite solo perché il test esiste; ogni task deve riportare comando, exit code, ambiente, limiti e confini non coperti.

## Limiti della baseline

Non sono stati eseguiti provider AI reali, browser E2E contro backend/persistenza, PostgreSQL concorrente, device nativi, repository remoti non clonati, deploy, webhook esterni o benchmark comparativi. Le credenziali non sono state lette né stampate. La baseline è documentale + controlli locali mirati e resta aperta dove indicato.
