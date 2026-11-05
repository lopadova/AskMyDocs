# Verifiche del consolidamento

Le verifiche sono state eseguite su una copia temporanea dello snapshot Git riconciliato, con dipendenze installate dai lockfile. La copia non contiene `.env` del progetto. Node 24.15.0, PHP 8.5.12, Composer 2.10.3, PHPUnit 12.5.40 e Vitest 5.0.5. I risultati strutturati sono in [checks.json](checks.json).

Il ramo è preparato per la revisione; **non è ancora pronto per il merge in produzione**. I gate rossi ereditati vengono conservati e resi visibili, senza rimuovere test o attenuarne le asserzioni.

## Contenuto e storia Git

- 2.343 file applicativi assegnati una sola volta a 15 commit tematici; 23 file del dossier nel sedicesimo commit.
- Tree applicativa finale identica a `9556db7859aa9314d86352f01e700e1a82774d6a` prima del dossier.
- 134 file esclusivi di main conservati identici. Tutti i file al di fuori delle riconciliazioni di main coincidono con develop, salvo una sola riga vuota rimossa a fine `frontend/src/styles/buttons.css`.
- `git diff --check`: passato.
- Riferimenti di main/develop conservati, backup nominati creati e bundle completo verificato.
- Gli elaborati preesistenti non tracciati in `docs/reports/` restano esclusi. Installazioni, build e database di verifica sono in una cartella temporanea.

## Controlli passati

| Controllo | Risultato |
|---|---|
| Installazione Composer pulita e tre patch del connettore API | Passato |
| `composer validate --no-check-publish` | Valido; warning già presente sul vincolo esatto beta.5 |
| Installazione npm pulita dal lock finale | Passato |
| TypeScript | Passato |
| Vitest React | 249 file, 2.009 test passati |
| Vitest legacy | 2 file, 34 test passati |
| Select e Dialog, compreso Escape nel menu annidato | 14 test passati; copertura originale invariata |
| Build frontend e widget | Passata; avviso non bloccante sui chunk grandi |
| Installazione e build frontend desktop Tauri | Passate; TypeScript/Vite, senza build native |
| Contratto delle security rule | Passato: 19 identificatori e 88 disposizioni |
| Client e campagne QA Python | 16 test passati |
| Compatibilità delle funzioni di produzione | 131 test PHP, 450 asserzioni passati |
| Migrazioni forward su SQLite pulito | Passate su database temporaneo dedicato |

I 131 test PHP mirati coprono 2FA, approvazioni delle azioni, principal MCP, budget fisici MCP, artifact del pack, export/import e routine Wiki, colonne Vision e canonicalizzazione. Sono un sottoinsieme della suite, non un sostituto dell’esecuzione completa.

## Gate aperti

### 1. Suite PHP completa: dipendenza Freshdesk non allineata

Comando: `vendor/bin/phpunit --order-by=default --testdox --stop-on-error --stop-on-failure`.

L’esecuzione si ferma dopo **4.293 test su 6.733**, 23.978 asserzioni, con un errore; riporta inoltre 3 skip e 2 deprecazioni PHP 8.5. Non viene dichiarata verde o completa.

`FreshdeskCaseStudyTest` non trova `Padosoft\AskMyDocsConnectorFreshdesk\CaseStudies\CaseStudyGeneratorContract`, usato da `app/Connectors/Freshdesk/OpenRouterCaseStudyGenerator.php`. Il lock sorgente e quello consolidato fissano entrambi Freshdesk **1.0.0**, mentre l’installazione di lavoro esistente dispone di **1.1.0**. Il generatore, il test e l’intera voce del lock sono invariati rispetto a develop: l’installazione pulita rende visibile una dipendenza già non riproducibile nella sorgente. Occorre rendere disponibile e bloccare una release del pacchetto che contenga il contratto, poi rieseguire la suite completa.

La prima esecuzione preliminare sul vecchio develop è stata interrotta per concentrare i controlli sullo snapshot finale pulito; non viene conteggiata come esito positivo.

### 2. Contratto architetturale del graph executor

`vendor/bin/phpunit --testsuite Architecture --no-progress`: 35 test, 371 asserzioni, un fallimento.

`GraphExecutorNotAdoptedTest` rileva `OperationNode.php -> FlowNodeHandler`, mentre il contratto storico vieta l’adozione del motore finché non è verificata la tenancy della sua persistenza. Il test va riconciliato con l’implementazione e con gli store effettivamente usati; questo risultato da solo non dimostra una fuga di dati. Lo stesso fallimento è stato riprodotto direttamente sul develop originale. Il test e il nodo non vengono modificati qui.

### 3. Controllo statico degli E2E

`bash scripts/verify-e2e-real-data.sh` produce cinque rilievi:

- `frontend/e2e/mcp-project-connection.spec.ts:66`.
- `frontend/e2e/pact-auth-consent.spec.ts:14`, `15`, `17`, `31`.

Riguardano intercettazioni di route senza il contratto o il marcatore richiesto. L’output del controllo è identico byte per byte sul develop originale e sullo snapshot consolidato. Non vengono aggiunte eccezioni generiche per far passare il gate.

### 4. Rollback SQLite del pacchetto FinOps

Il rollback del database temporaneo si arresta nella migrazione `2026_08_25_000002_add_invocation_id_to_ai_finops_usage_ledger_table` del pacchetto FinOps: SQLite rifiuta di eliminare `invocation_id` finché esiste l’indice `ai_finops_usage_ledger_invocation_id_index`.

Il lock di FinOps **1.7.0** coincide con quello di develop; la migrazione nella vecchia installazione e in quella pulita è identica. Il risultato riguarda SQLite. Il ciclo di migrazione sul motore di produzione PostgreSQL resta da eseguire e non viene dedotto da questo esito.

## Verifiche successive alla preparazione locale

Prima del merge restano le correzioni e la ripetizione dei gate rossi, Playwright completo su quattro shard, migrazioni forward/rollback su PostgreSQL pulito, build native previste dalla release, CI remota e ciclo Copilot. Nessuna pubblicazione, apertura PR, modifica alle protezioni o riscrittura di develop è stata eseguita.
