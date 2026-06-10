# Piano di remediation — PR #266 (KITT embeddable widget)

> **Branch:** `feature/kitt-host-tools-foundation`
> **Base:** `main` @ `a90ccb2` · **PR head al momento della review:** `a1860c4`
> **Origine:** `/code-review max pr266` — 9 angoli di finder + sweep + verifier dedicati.
> **Stato:** ❌ NON mergeabile. 2 blocker CI/deploy, 3 feature core rotte in produzione (mascherate da test con mock-object), cluster di sicurezza sulla superficie pubblica del widget.

## Come usare questo documento (per l'agente esecutore)

1. Lavora **sul branch `feature/kitt-host-tools-foundation`** (è già il branch della PR). Non toccare `main` (R37).
2. Esegui le **fasi nell'ordine** (FASE 0 → 7). Le fasi 0–2 sbloccano CI e produzione: falle per prime.
3. I **numeri di riga sono indicativi** (riferiti al commit di review): localizza ogni punto tramite gli **anchor di codice** citati, non tramite la riga.
4. Dopo **ogni fix** rilancia i test mirati indicati in "Verifica". A fine di ogni FASE rilancia la suite completa.
5. Regole del repo da rispettare sempre (CLAUDE.md §7): **R14** (mai 200/empty su errore — status per *tipo* di eccezione), **R21** (invariante single-use atomica dentro la transaction), **R30/R31** (tenant scoping), **R16** (il test esercita davvero ciò che il nome promette), **R13** (E2E su dati reali, `page.route` solo su servizi esterni), **R4** (mai ignorare return di chiamate side-effecting).
6. **Loop critic locale + R36** prima del push (R40): copilot-cli fino a 0 must-fix, poi push, poi loop Copilot cloud + CI verde.

### Comandi di verifica globali

```powershell
# Backend PHP (SQLite in-memory)
vendor/bin/phpunit --testsuite=Unit,Feature
vendor/bin/phpunit tests/Feature/Widget tests/Unit/Widget tests/Feature/Admin/WidgetAdminControllerTest.php
vendor/bin/phpunit tests/Architecture   # R30/R31 gate

# Frontend unit (vitest)
npm test

# Build del widget + SPA
npm run build
npm run build:widget   # deve produrre public/widget/askmydocs-widget.js

# Gate R13 (deve uscire 0)
bash scripts/verify-e2e-real-data.sh

# E2E
npm run e2e
```

---

## Indice findings

| # | ID | Severità | File | Fase |
|---|----|----------|------|------|
| 1 | CHUNK-OBJECT-BUG | 🔴 Critico | `SearchKnowledgeBaseTool.php` | 1 |
| 2 | ACTION-NO-ALLOWLIST | 🔴 Critico (sec) | `widget/core/executor.ts` + snapshot/validator | 3 |
| 3 | SNAPSHOT-PASSWORD-LEAK | 🔴 Alto (sec) | `widget/dom/snapshot.ts` | 3 |
| 4 | REFUSAL-BYPASS | 🔴 Alto | `WidgetOrchestratorService.php` | 1 |
| 5 | WIDGET-BUNDLE-MISSING | 🔴 Blocker | `package.json` / `tests.yml` | 0 |
| 6 | R13-GATE-FAIL | 🔴 Blocker CI | `e2e/widget.spec.ts` / `verify-e2e-real-data.sh` | 0 |
| 7 | PROVIDER-TOOLS-DROP | 🔴 Alto | `WidgetOrchestratorService.php` | 1 |
| 8 | HOST-TOOLS-TURN2 | 🔴 Alto | `widget/core/bridge.ts` | 1 |
| 9 | NAV-BYPASS (open redirect) | 🟠 Medio-alto (sec) | `WidgetToolValidator.php` + `executor.ts` | 3 |
| 10 | TOKEN-MODE-DEAD | 🟠 Medio (feature rotta) | `ResolveWidgetKey.php` | 2 |
| 11 | TOKEN-ORIGIN-NULL | 🟠 Medio (sec) | `WidgetSessionTokenService.php` | 2 |
| 12 | TOKEN-BURN-429 | 🟠 Medio | `ResolveWidgetKey.php` | 2 |
| 13 | TOKEN-BURN-INACTIVE | 🟠 Medio | `WidgetSessionTokenService.php` | 2 |
| 14 | TOKEN-PLAINTEXT | 🟠 Medio (sec) | `WidgetSessionTokenService.php` | 2 |
| 15 | EMPTY-TOOLS-400 | 🟠 Medio-alto | `WidgetOrchestratorService.php` | 1 |
| 16 | DEPTH-RESET | 🟠 Medio (costo/DoS) | `widget/core/bridge.ts` | 4 |
| 17 | TRANSPORT-NO-TIMEOUT | 🟠 Medio | `widget/core/transport.ts` | 4 |
| 18 | DUP-LABEL-500 | 🟠 Medio (R14) | `WidgetKeyAdminController.php` | 4 |
| 19 | EXECTOOL-NO-CAP | 🟠 Medio | `WidgetSessionController.php` | 4 |
| 20 | DUP-TOOL-STEP | 🟡 Basso-medio | `WidgetSessionController.php` | 4 |
| 21 | SKILL-DIVERGE | 🟡 Basso-medio | `WidgetOrchestratorService.php` | 4 |
| 22 | IDEMPOTENCY-DEAD | 🟡 Basso-medio (R21/R9) | migration steps + controller | 4 |
| 23 | SNAPSHOT-SIZE | 🟠 Medio (DoS) | `WidgetSnapshotValidator.php` | 3 |
| 24 | CORS-DOUBLE-ENGINE | 🟡 Basso-medio | `HandleWidgetCors.php` / `config/cors.php` | 4 |
| 25 | BUILDMSG-FULL-LOAD | 🟡 Perf | `WidgetOrchestratorService.php` | 5 |
| 26 | LASTUSED-WRITE | 🟡 Perf | `ResolveWidgetKey.php` | 5 |
| 27 | SESSION-LIST-N+1 + per_page | 🟡 Perf (R3) | `WidgetSessionAdminController.php` | 5 |
| 28 | MISSING-INDEX | 🟡 Perf | migration `widget_sessions` | 5 |
| 29 | TOKEN-TABLE-UNBOUNDED | 🟡 Perf | `PruneWidgetSessionsCommand.php` | 5 |
| 30 | CHATLOG-MISSING | 🟡 Osservabilità | `WidgetOrchestratorService.php` | 5 |
| 31 | ROLE-GATE-MISMATCH | 🟠 Medio (R32) | `routes/index.tsx` / nav | 6 |
| 32 | MUTATION-NO-ERROR | 🟠 Medio (R14/R11) | `WidgetKeysView.tsx` | 6 |
| 33 | STATUS-FILTER-SUBSET | 🟡 Basso (R18) | `WidgetSessionsView.tsx` | 6 |
| 34 | CMD-NAME-DRIFT | 🟠 Medio (R9/R20) | `EmbedCodeDialog.tsx` / README | 6 |
| 35 | THEME-MODE-NOOP | 🟡 Basso | `widget/ui/panel.ts` / Appearance | 6 |
| 36 | FILTER-KEYID-DEAD | 🟡 Basso | `WidgetSessionsView.tsx` | 6 |
| 37 | ISVISIBLE-DEAD | 🟡 Basso | `widget/core/executor.ts` | 4 |
| 38 | WAITFOR-NAN | 🟡 Basso | `widget/core/executor.ts` | 4 |
| 39 | BINARY-TEST-FILE | 🟡 Hygiene | `widget/ui/styles.test.ts` | 7 |
| 40 | DS-STORE | 🟡 Hygiene | `.DS_Store` / `.gitignore` | 7 |
| 41 | ENV-EXAMPLE-DRIFT | 🟡 Hygiene (R6) | `.env.example` / README | 7 |
| 42 | PII-REUSE | 🟡 Coerenza | `WidgetPiiMasker.php` | 7 |
| 43 | OBSERVER-DROP | ⚪ Deferred (dead code) | `widget/dom/Observer.ts` | 7 |
| 44 | DEMO-KEY-MINT | 🟡 Hardening | `routes/web.php` | 3 |
| — | SHADCN-2ND-DESIGN-SYSTEM | ⚪ Decisione | `components/ui/*` | nota finale |

**Falsi allarmi già verificati (NON intervenire):** `javascript:`-URL XSS pura (il validator server blocca `javascript:`/`data:`/`vbscript:` ed è default-deny) → resta solo l'open-redirect #9; `WidgetPiiMasker` ReDoS catastrofico (PCRE2 JIT mantiene il pattern email lineare); `/u` flag sul masker (il problema è la character class ASCII-only, non byte-vs-char).

---

## FASE 0 — Sblocco CI & deploy (DA FARE PER PRIMA)

### #5 WIDGET-BUNDLE-MISSING — il bundle del widget non viene mai costruito

**Problema:** `build:widget` (in `package.json`, unico produttore di `public/widget/askmydocs-widget.js`) non è agganciato né a `npm run build` né a `.github/workflows/tests.yml`, e l'output è gitignorato (`.gitignore` `+/public/widget/`). Su checkout pulito (CI o deploy) il loader **non esiste** → `widget-demo.blade.php` e gli E2E caricano `/widget/askmydocs-widget.js` → 404/SPA-HTML → launcher non monta → 6 spec E2E in timeout; in produzione il `<script src="/widget/askmydocs-widget.js">` documentato è rotto su ogni pagina.

**Fix:**
1. In `package.json`, fai sì che la build del widget venga prodotta in CI/deploy. Opzioni (scegline UNA, preferita A):
   - **(A)** Aggiungi `build:widget` alla pipeline di build principale:
     ```json
     "build": "tsc -b frontend/tsconfig.json && vite build && vite build --config vite.widget.config.ts",
     ```
   - **(B)** Lascia `build` separato ma aggiungi uno step esplicito `npm run build:widget` nel workflow CI **prima** del job Playwright e nello script di deploy/README.
2. In `.github/workflows/tests.yml`, **prima dello step Playwright**, assicurati che `public/widget/askmydocs-widget.js` esista (lo step CLI già esistente per `migrate:fresh` di R38 è il posto giusto in cui inserire `npm run build:widget` se scegli B).
3. Verifica che `vite.widget.config.ts` produca davvero `outDir: public/widget` + `fileName: askmydocs-widget.js`, bundle IIFE/UMD, **niente React** importato dentro `frontend/src/widget/*`, minificato, no sourcemap in produzione.
4. Documenta `build:widget` nel README (sezione deploy del widget) — il file `frontend/src/widget/README.md` deve citare lo step.

**Verifica:** `npm run build` (o `npm run build:widget`) deve creare `public/widget/askmydocs-widget.js`; `npm run e2e` non deve più andare in timeout sul launcher.

---

### #6 R13-GATE-FAIL — `page.route` su rotte interne fa fallire il gate R13

**Problema:** `frontend/e2e/widget.spec.ts` intercetta con `page.route()` rotte **interne** `/api/widget/*` (righe ~45, 97, 136, 150, 193, 212). `scripts/verify-e2e-real-data.sh` (agganciato a `tests.yml`) non ha `/api/widget/` nella allowlist e gli scenari agentici non hanno il marker `R13: failure injection` → lo step "Verify E2E real-data rule (R13)" esce 1 → **job Playwright rosso ad ogni run**. Inoltre gli scenari agentici non esercitano mai l'orchestratore reale (vedono solo il payload stubbato).

**Fix (R13-conforme):**
1. **Riscrivi gli scenari happy-path agentici** in modo che girino contro il backend reale (Laravel + SQLite seeded), come gli altri E2E. L'unico `page.route` ammesso è verso il **provider AI esterno** (OpenAI/OpenRouter/ecc.) — quello è fuori dal boundary applicativo. Tutto ciò che è `/api/widget/*`, `/api/admin/*`, `/sanctum/*`, `/testing/*` deve passare dal backend reale.
2. Per i casi di **failure-mode** che richiedono iniezione su rotta interna (es. simulare un 500/429), tieni l'intercettazione **solo se** esiste già nello stesso file la variante happy-path su dati reali, e marca la riga con un commento `// R13: failure injection`.
3. Se servono fixture/seed per-scenario, usa `/testing/reset` + `/testing/seed` tramite l'helper `resetDb()`/`seeded` (NON `request.post('/testing/reset')` diretto — R38).
4. Mocka il provider AI esterno a livello di **backend** quando possibile (es. `Http::fake()` lato server tramite un seeder/endpoint di test) invece di intercettare `/api/widget/*` lato browser.

**Verifica:** `bash scripts/verify-e2e-real-data.sh` deve uscire **0**. `npm run e2e` verde.

> ⚠️ #5 e #6 sono interdipendenti: senza #5 gli E2E non hanno il bundle; senza #6 il gate è rosso comunque. Chiudi entrambi prima di passare oltre.

---

## FASE 1 — Feature core rotte in produzione

### #1 CHUNK-OBJECT-BUG — il tool `search_knowledge_base` va in 500 ad ogni ricerca con risultati

**Problema:** `app/Services/Widget/AiTool/SearchKnowledgeBaseTool.php` (~righe 95-100) mappa i chunk con **sintassi object** mentre in produzione i chunk sono **array**. La riga `'id' => (string) $chunk->id` è una **lettura nuda** (le righe 97-99 sono `??`-guarded e resterebbero silenti, ma la 96 no) → PHP 8.3 lancia "Attempt to read property id on array" → `ErrorException` via `HandleExceptions` → `execTool` cattura solo `InvalidArgumentException` → **HTTP 500 su ogni ricerca KB che restituisce ≥1 risultato**. Il test `SearchKnowledgeBaseToolTest` lo maschera con un fixture `(object)` (classe bug R13/R16, identica a quella del refusal-gate v8.1).

**Riferimenti shape reale:** `KbSearchService::search()` mappa ad array con chiavi `chunk_id`, `chunk_text`, `vector_score`, `document.title` (vedi commento `// ── Map to array format ──` e `ChatRetrievalService.php` "chunks are arrays in production"). `Reranker::rerank()` è tipato `@return Collection<int, array>`.

**Fix:**
1. In `SearchKnowledgeBaseTool::execute()`, riscrivi la map usando **accesso array** shape-agnostico con `data_get()` (come fa il blade `kb_rag` e `ChatRetrievalService::buildCitations()`):
   ```php
   $rows = $result->primary->map(fn (array $chunk) => [
       'id'              => (string) data_get($chunk, 'chunk_id', data_get($chunk, 'id', '')),
       'title'           => data_get($chunk, 'document.title', data_get($chunk, 'heading_path', 'Documento')),
       'similarity'      => round((float) data_get($chunk, 'rerank_score', data_get($chunk, 'vector_score', 0)), 3),
       'content_preview' => mb_substr((string) data_get($chunk, 'chunk_text', ''), 0, 200),
   ])->all();
   ```
   Adatta le chiavi a quelle effettivamente prodotte da `ChatRetrievalService::retrieve()` (leggi la sorgente e allinea — NON indovinare).
2. **Meglio ancora (altitude):** riusa direttamente `ChatRetrievalService::buildCitations($result)` / il metodo che già produce le citazioni nelle altre 3 channel, invece di reimplementare lo shaping. Vedi anche #4/#30.
3. **Correggi il test (R16):** in `tests/Unit/Widget/SearchKnowledgeBaseToolTest.php` sostituisci il fixture `(object)[...]` con la shape **array reale** (`['chunk_id' => ..., 'chunk_text' => ..., 'vector_score' => ..., 'document' => ['title' => ...]]`). Il test deve fallire con il codice vecchio e passare col nuovo.
4. Verifica che `execTool` (`WidgetSessionController`) non nasconda altri tipi di errore: valuta un `catch (\Throwable)` che mappi a un 422/500 *esplicito* con payload, anziché lasciar passare un `ErrorException` come 500 generico (R14).

**Verifica:** `vendor/bin/phpunit tests/Unit/Widget/SearchKnowledgeBaseToolTest.php` + un Feature test che POSTa `/exec-tool` con `search_knowledge_base` su dati seeded reali e asserisce 200 + righe con `title`/`similarity` reali.

---

### #4 REFUSAL-BYPASS — il widget risponde a query che le altre channel rifiutano

**Problema:** `WidgetOrchestratorService.php:~135` chiama `ChatRetrievalService::retrieve()` ma **salta** il gate condiviso `shouldRefuse()` / `RetrievalGrounding` che `KbChatController`, `MessageController`, `MessageStreamController` applicano tutti. `retrieve()` ammette chunk al floor 0.30; il grounding gate richiede `rerank≥0.25 OR vector≥0.45`. Una query che le 3 channel autenticate rifiutano deterministicamente (senza chiamare l'LLM) ottiene sulla **widget pubblica** una risposta confidente con citazioni; `SearchFailureRecorder` non logga mai i gap del widget. Anche il path BE-tool (`SearchKnowledgeBaseTool`) è ungated (solo `isEmpty()`).

**Fix:**
1. In `WidgetOrchestratorService`, dopo `retrieve()`, applica lo stesso gate:
   ```php
   if ($this->retrieval->shouldRefuse($result)) {
       // ramo "nessun contesto rilevante": NON chiamare l'LLM,
       // restituisci il messaggio di refusal localizzato + registra il gap
       $this->recordSearchFailure(...); // come fanno KbChatController/MessageController
       return $this->finishWithRefusal($session, reason: 'no_relevant_context');
   }
   ```
   Allinea l'esatta firma a `KbChatController::shouldRefuse` usage.
2. Filtra i chunk passati al prompt/citazioni con `RetrievalGrounding::grounded()` (solo i grounded entrano nel context `### Documenti` e in `buildCitations`).
3. Applica lo stesso `shouldRefuse()` anche in `SearchKnowledgeBaseTool::execute()` (path BE-tool): sotto-floor → "nessun risultato pertinente", non tabella dati.
4. Registra i gap via `SearchFailureRecorder` come le altre channel.

**Verifica:** Feature test: una query con chunk sotto-floor → il widget risponde con refusal deterministico (provider AI **non** chiamato — usa `shouldNotReceive('chat')` di Mockery, R26) e scrive una riga in `SearchFailureRecorder`.

---

### #7 PROVIDER-TOOLS-DROP — su anthropic/gemini/regolo il widget agentico è morto e silenzioso

**Problema:** l'orchestratore passa `options['tools']`/`tool_choice` a `AiManager::chatWithHistory` e si dirama su `$response->toolCalls`, ma **solo** `OpenAiProvider`/`OpenRouterProvider` forwardano i tools e popolano `toolCalls`. `AnthropicProvider`, `GeminiProvider`, `RegoloProvider` li droppano e non settano mai `toolCalls` → con `AI_PROVIDER=anthropic|gemini|regolo` ogni turno cade in `finishWithAnswer`: DOM tool, host tool e `search_knowledge_base` non scattano mai. Il system prompt continua a dire "emetti UNA tool_call" → degrado silenzioso a chat-only, **nessun errore né log** (R43 OFF-path non testato). Il repo ha già il pattern `TOOL_CAPABLE_PROVIDERS = ['openai','openrouter']` + `supportsToolCalling()` in `HostBridge`/`McpToolCallingService` — l'orchestratore widget non ha l'equivalente.

**Fix:**
1. Aggiungi un gate `supportsToolCalling()` nell'orchestratore, riusando la stessa lista/contratto di `HostBridge` (`TOOL_CAPABLE_PROVIDERS`). Determina il provider attivo (`config('ai.default')`, dato che `config/widget.php` non override).
2. Se il provider **non** supporta tool-calling:
   - **(A)** Degrada esplicitamente a RAG-answer-only **adattando il system prompt** (niente istruzione "emetti tool_call") e logga un warning una-tantum; **oppure**
   - **(B)** (preferito a lungo termine) implementa il tool-calling per Anthropic/Gemini/Regolo nei rispettivi provider — è scope ben più ampio: se non in questa PR, scegli (A) e apri una issue/ADR.
3. **Testa entrambi gli stati (R43):** un test con provider tool-capable (tool loop attivo) e uno con provider non-tool-capable (degrado pulito, nessun 500, nessuna istruzione tool nel prompt).

**Verifica:** Feature test parametrico sul provider; con `FakeProvider`/anthropic il widget risponde in chat-only senza errori; con openai esegue il tool loop.

---

### #15 EMPTY-TOOLS-400 — skill invalida → array tools vuoto → 400 OpenAI → 500 ad ogni messaggio

**Problema:** le option includono **sempre** `'tools' => $tools` (solo `tool_choice` passa a `'none'` se vuoto). La validazione admin di `skill` è `nullable|string|max:100` senza check di formato `@version` né esistenza nel registry → uno skill tipo `my-assistant` (senza `@1`) fa tornare `WidgetSkillRegistry::get` null → `$tools = []` → `OpenAiProvider` forwarda `"tools": []` → OpenAI risponde 400 → `$response->throw()` → nessun try/catch in `start()/step()` → **500 su ogni messaggio**. Il repo ha già la convenzione opposta in `HostBridge` (`if ($toolsPayload !== [])` omette la chiave).

**Fix:**
1. Nell'orchestratore, **ometti** del tutto `tools`/`tool_choice` dalle option quando `$tools === []` (come `HostBridge`):
   ```php
   $options = [/* model, temperature, ecc. */];
   if ($tools !== []) {
       $options['tools'] = $tools;
       $options['tool_choice'] = 'auto';
   }
   ```
2. **Valida lo skill in admin** (`WidgetKeyAdminController` store/update): regola che imponga il formato `^[a-z0-9][a-z0-9-]*@[0-9]+$` **e/o** l'esistenza nel `WidgetSkillRegistry`; altrimenti 422 chiaro. (vedi anche #18.)
3. **Testa OFF-path (R43):** skill mancante/invalida → il widget degrada a chat-only/RAG-only senza 500.

**Verifica:** test che, con `$tools=[]`, asserisce che le option passate a `chatWithHistory` **non** contengono la chiave `tools` (mirror di `HostBridgeTest::assertArrayNotHasKey`).

---

### #8 HOST-TOOLS-TURN2 — gli host tool funzionano solo al turno 1

**Problema:** solo `startSnapshot()` (turno 1) aggancia `snapshot.host_tools`; ogni `/step` successivo (`bridge.ts` ~114, 242, 313, 419) invia `buildSnapshot()` nudo, mentre `WidgetOrchestratorService::resolveHostTools()` rilegge `host_tools` dallo snapshot della richiesta **corrente** ad ogni turno (il manifest è solo un *gate*, non sorgente di tool). Dal turno 2 la lista host-tool è vuota → la feature omonima della PR ("host-tools") muore; se l'LLM rie-emette un host tool dalla history, il validator lo rifiuta come "not enabled" e il `consecutive_errors` porta la sessione a `blocked`.

**Fix (scegli UNA):**
- **(A) Lato client (semplice):** in `bridge.ts`, fai sì che **ogni** snapshot inviato (turno 1 e successivi) includa `host_tools` se `this.hostTools.length > 0`. Centralizza in `buildSnapshot()`/un wrapper così tutti i call site (114/242/313/419) lo ottengono.
- **(B) Lato server (più robusto):** persisti la lista host-tool **sulla sessione** al primo turno e fai sì che `resolveHostTools()` faccia fallback alla lista persistita quando lo snapshot corrente non la porta. Così non dipendi dal client che la ri-spedisce ad ogni turno.

**Raccomandato:** (B) come fonte di verità + (A) per non rispedire payload inutile (vedi anche #25 sul peso degli snapshot). Decidi e documenta.

**Verifica:** test E2E/Feature multi-turno: al turno 2 una `articoli__search` (host tool) deve ancora essere disponibile e validare OK.

---

## FASE 2 — Sottosistema session-token (è shipped-broken end-to-end)

> Tutto il path `wt_` è **irraggiungibile** oggi (#10), quindi gli altri difetti del token sono latenti finché non sistemi #10. Ma vanno corretti **insieme**, perché appena #10 è fixato i bypass diventano live.

### #10 TOKEN-MODE-DEAD — la session-token mode fa 401 end-to-end

**Problema:** `ResolveWidgetKey::handle()` ritorna 401 `widget_key_missing` quando manca `X-Widget-Key` **prima** del branch bearer `wt_` (e prima richiede pure la lookup della key), ma `transport.ts` in token mode invia **solo** `Authorization: Bearer wt_…` (e `transport.test.ts` asserisce `X-Widget-Key` undefined). Quindi ogni richiesta token-mode 401-a prima di arrivare a `resolveFromSessionToken`. Nessun Feature test HTTP invia un bearer `wt_`.

**Fix:**
1. In `ResolveWidgetKey::handle()`, **anteponi** il branch `wt_`: se `Authorization: Bearer wt_…` è presente, risolvi via token (che già recupera key+tenant) **senza** richiedere `X-Widget-Key`. Solo nel ramo non-token richiedi/lookup `X-Widget-Key`.
2. Aggiungi un **Feature test HTTP** che mint-a un token e chiama `/sessions/start` con solo `Authorization: Bearer wt_…` → deve passare l'auth.

**Verifica:** il nuovo Feature test verde; `transport.test.ts` resta coerente.

---

### #11 TOKEN-ORIGIN-NULL — bypass del binding d'origine (anche flag dal security review)

**Problema:** in `WidgetSessionTokenService::consume()` (~riga 84) il check d'origine è dietro `$row->origin !== null && $origin !== null`, e `ResolveWidgetKey` non ri-controlla `allowed_origins` dopo la risoluzione del token. (a) Token mint-ato senza Origin → `origin=null` → replay da qualsiasi origine. (b) Token origin-bound replay-ato via curl senza header Origin → `$origin===null` corto-circuita il check → binding bypassato. Il path token è **strettamente più debole** di quello browser.

**Fix (suggerito anche dal security review automatico):**
```php
if ($row->origin !== null) {
    if ($origin === null) {
        return null; // token origin-bound ma richiesta senza Origin → rifiuta
    }
    $normalizedRequest = rtrim(strtolower(trim($origin)), '/');
    $normalizedToken   = rtrim(strtolower(trim($row->origin)), '/');
    if ($normalizedRequest !== $normalizedToken) {
        return null;
    }
}
```
Inoltre: valuta di **ri-applicare `WidgetKey::originAllowed($origin)`** dentro `resolveFromSessionToken` dopo la risoluzione, così il path token non è mai più debole dell'allowlist della key. **Centralizza** la normalizzazione d'origine: oggi `rtrim(strtolower(trim()))` è duplicato in 3 punti (`WidgetKey::normalizeOrigin`, `WidgetSessionTokenService::consume`, `WidgetToolValidator`); usa l'helper del model come unica implementazione (R19/altitude).

**Verifica:** test unit: token origin-bound + richiesta senza Origin → null; token con origine diversa → null; token mint-ato senza origine → comportamento deciso (consigliato: rifiuta o lega all'allowlist della key).

---

### #12 TOKEN-BURN-429 — il 429 brucia il token single-use

**Problema:** in `resolveFromSessionToken` il token viene **consumato** (`consumed_at` committato) **prima** del check di rate-limit. Un 429 brucia permanentemente il token; il retry conforme poi 401-a.

**Fix:** sposta il check `rateLimited()` **prima** di `consume()` (come fa già il path pk-mode, che controlla il rate limit prima di qualsiasi mutazione di stato — R21).

**Verifica:** test: key a rate-limit + token valido → 429 e il token resta **non** consumato (riusabile dopo il Retry-After).

---

### #13 TOKEN-BURN-INACTIVE — il token viene bruciato anche se la key è revocata

**Problema:** `consume()` (~riga 93) setta e committa `consumed_at` **prima** del check `key === null || !key->is_active`. Presentando un token valido per una key revocata, il token viene distrutto senza concedere accesso. La mutazione non è condizionata al successo (R21).

**Fix:** dentro la `DB::transaction`, **prima** committa nulla: verifica `isConsumed()`, scadenza, origine **e key attiva**; solo se TUTTO ok esegui `forceFill(['consumed_at' => now()])->save()`. Ritornare `null` dalla closure non fa rollback, quindi l'ordine dei controlli è ciò che conta.

**Verifica:** test: token valido + key `is_active=false` → null e `consumed_at` **resta** null.

---

### #14 TOKEN-PLAINTEXT — il bearer è salvato in chiaro a riposo

**Problema:** `mint()` (~riga 43) persiste il bearer **in chiaro** in `widget_session_tokens.token` e `consume()` fa `where('token', $plain)`, a differenza del pattern repo (`AdminCommandNonce`/`CommandRunnerService` salvano solo `hash('sha256', ...)`). Un dump/replica/SQLi espone token live e replay-abili. Il docblock del model cita pure una UNIQUE su `consumed_at` che la migration non crea (drift R9).

**Fix:**
1. Salva **solo** `hash('sha256', $plain)` nella colonna (rinominala concettualmente in `token_hash` se vuoi chiarezza; la colonna è `string(128) unique` quindi sha256 ci sta). Restituisci il plaintext **solo** al chiamante di `mint()`, mai persistito.
2. `consume()` cerca via `where('token_hash', hash('sha256', $bearer))`.
3. Rimuovi dal docblock del model il riferimento alla UNIQUE su `consumed_at` inesistente (o crea davvero il constraint se la regola di business lo richiede — vedi R21 checklist; per single-use con `consumed_at` nullable, una partial unique non è banale su SQLite di test, quindi probabilmente basta correggere il docblock).
4. Migration: se la colonna esiste già nella migration di questa PR (`2026_06_01_075908_create_widget_session_tokens_table.php`), **modifica direttamente quella migration** (non è ancora in produzione su `main`) per riflettere `token_hash`.

**Verifica:** test: dopo `mint()`, nel DB non compare il plaintext; `consume()` col plaintext funziona via hash.

---

## FASE 3 — Sicurezza: trust boundary azioni/snapshot (superficie pubblica)

> Il widget monta **direttamente nel DOM della host page** (shadow root aperto, **non** iframe): la widget JS gira nell'origine JS della host page. Il boundary "il server/LLM dice → il client esegue azioni DOM" è quindi critico.

### #2 ACTION-NO-ALLOWLIST — l'LLM può cliccare controlli distruttivi della host page

**Problema:** `executor.ts` (`findActionTarget`/`click` ~80-106, `findField`/`type` ~170-210, `submit` ~274-283) risolve i target per id/`[data-testid]`/`[name]`/match testo su **tutto** il documento host, senza allowlist `data-kitt`; `click` è `confirm:false`; `submit_form` fa fallback a `document.forms[0]`. Il vincolo server è debole: il catalog `click` ha `needs:['target']` ma il validator matcha contro `page_outline.buttons_unannotated[]` che raccoglie **tutti** i bottoni non annotati (cap 80). Un LLM prompt-injected (doc KB avvelenato o testo di pagina) può emettere `click target='Delete account'`/`'Logout'` → match per textContent → nessuna conferma → `.click()` eseguito sotto la sessione dell'utente.

**Fix (difesa a strati, applicane il più possibile):**
1. **Allowlist client-side per annotazione:** in `executor.ts`, restringi `click`/`type`/`select` ai soli elementi che portano un attributo `data-kitt-action`/`data-kitt-field` (o che sono nello snapshot validato). Rifiuta i target risolti solo-per-testo su elementi non annotati.
2. **Rifiuta i campi sensibili in `type`:** mai scrivere in `input[type=password]`/`[type=hidden]`/`autocomplete=current-password|cc-*`.
3. **`submit_form`:** elimina il fallback a `document.forms[0]`; richiedi una form esplicitamente annotata/target.
4. **Conferma obbligatoria per azioni distruttive:** rendi `click` (e tutte le azioni mutanti) soggette a `confirmation_required` quando il target non è in una allowlist "safe"; verifica che il gate `onConfirm` in `bridge.ts` scatti davvero per quelle azioni.
5. **Lato server:** vincola la lista delle azioni emettibili dall'LLM ai soli target presenti nello snapshot **annotato** (non in `buttons_unannotated`), oppure introduci una policy `default_policies` per-skill che marca quali azioni richiedono conferma.

**Verifica:** test (vitest + Feature): un tool_call `click` verso un elemento non annotato → rifiutato/loggato; `type` verso password → rifiutato; `submit_form` senza form annotata → rifiutato; azione mutante annotata → richiede conferma.

---

### #3 SNAPSHOT-PASSWORD-LEAK — i valori dei campi password/hidden finiscono nel prompt LLM e nel DB

**Problema:** `snapshot.ts` `fields()` (~riga 200) serializza `input.value` per ogni elemento `data-kitt-field`-annotato; l'**unico** gate di sensibilità è l'attributo manuale `data-kitt-sensitive`. Nessuna esclusione automatica di `type=password`/`type=hidden`/`autocomplete=cc-*`. Il server (`WidgetSnapshotValidator::enforceSensitiveNull`) annulla i valori **solo** se il client aveva già marcato `sensitive`. Un campo password annotato senza `data-kitt-sensitive` → la password in chiaro entra in `snapshot.fields[].value` → POST `/sessions/start|step` → embeddata **non mascherata** nel system prompt `widget_kitt` (inviata al provider esterno) e persistita in `widget_session_steps.snapshot_in_json` (il masker regex non prende le password).

**Fix:**
1. **Lato client (`snapshot.ts` `fieldValue`/`fields`):** escludi **automaticamente** dal valore i campi con `type=password`, `type=hidden`, `autocomplete` che inizia per `cc-`/`current-password`/`new-password`. Per questi, `value=null` a prescindere da `data-kitt-sensitive`.
2. **Lato server (`WidgetSnapshotValidator`):** **ri-derivi** la sensibilità dal tipo/`autocomplete` del campo (non fidarti solo del flag del client) e annulla il valore. Difesa-in-profondità: anche se il client sbaglia, il server non persiste mai una password.
3. (Opzionale ma consigliato) escludi i campi sensibili anche dal prompt, non solo dalla persistenza.

**Verifica:** test (vitest): snapshot con `<input type=password>` annotato senza `data-kitt-sensitive` → `value` null nel payload. Feature (PHP): il validator annulla il valore di un campo `type=password` anche con `sensitive` assente.

---

### #9 NAV-BYPASS — open redirect via `/\evil.com` (anche flag dal security review)

**Problema:** `WidgetToolValidator::navigateAllowed` (~riga 199) rifiuta il `//` letterale ma il ramo path-relativo accetta qualsiasi altro URL con singolo `/` iniziale, quindi `/\evil.com` (slash-backslash) passa prima che l'allowlist sia consultata; `executor.ts` `navigate()` fa `location.href = url` senza ri-validazione client. I browser normalizzano `\`→`/` per http(s) → la host page naviga su `https://evil.com` = open redirect guidato dall'output LLM.
> Nota: la XSS `javascript:` pura è **già bloccata** dal validator (blocklist `javascript:`/`data:`/`vbscript:` + default-deny) — **non** è un problema. Resta solo l'open-redirect.

**Fix (entrambi i lati — difesa in profondità):**
1. **Server (`WidgetToolValidator`):** nel ramo path-relativo, **rifiuta i backslash** (e percent-encoding equivalenti `%5c`) e normalizza prima del check: tratta `\` come `/`. In pratica, se dopo aver sostituito `\`→`/` l'URL inizia con `//`, rifiuta.
2. **Client (`executor.ts` `navigate`):** valida lo scheme **e l'origine** prima di navigare. Attenzione: il fix scheme-only suggerito dal review automatico **non** chiude l'open-redirect, perché `new URL('/\\evil.com', location.href)` risolve a `https://evil.com/` con `protocol='https:'` che passerebbe. Serve anche il check d'origine:
   ```ts
   const parsed = new URL(url, location.href);
   const sameOrigin = parsed.origin === location.origin;
   if (!sameOrigin && !isAllowlistedOrigin(parsed.origin)) {
       return fail('navigate_to', 'Cross-origin navigation not allowed.');
   }
   if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') {
       return fail('navigate_to', 'Only http(s) URLs allowed.');
   }
   location.href = parsed.toString();
   ```

**Verifica:** test unit server: `'/\\evil.com'` e `'/%5cevil.com'` → `navigateAllowed` false. Test vitest client: `navigate('/\\evil.com')` → fail, nessuna navigazione.

---

### #23 SNAPSHOT-SIZE — caps solo sui conteggi, nessun limite di byte

**Problema:** `WidgetSnapshotValidator` (~riga 55) impone solo cap sui **conteggi** (500 fields / 200 actions / 50 regions), **nessun** cap per-stringa o totale. Uno snapshot con conteggi conformi ma stringhe da molti KB passa la validazione, fa esplodere il budget token del prompt (JSON-encoded in `widget_kitt`) e gonfia la persistenza longText ad ogni turno. Limitato solo da `post_max_size`.

**Fix:**
1. Aggiungi in `WidgetSnapshotValidator` un cap **per-stringa** (es. label ≤256, value ≤1024, text ≤2048 — allinea ai cap già usati lato client in `snapshot.ts`) e un cap **totale** sui byte dello snapshot serializzato (es. configurabile `WIDGET_SNAPSHOT_MAX_BYTES`).
2. Tronca o rifiuta (decidi: troncare con `…` è più gentile; rifiutare con 422 è più sicuro) — preferisci **troncare** i valori e **rifiutare** se il totale supera il cap byte.
3. Aggiungi la chiave config in `config/widget.php` + `.env.example` (vedi #41).

**Verifica:** test: snapshot con 500 fields da 10KB ciascuno → 422 o valori troncati; il totale serializzato non supera il cap.

---

### #44 DEMO-KEY-MINT — `/widget-demo` conia una key attiva

**Problema:** `routes/web.php` (~riga 213) `GET /widget-demo`, gated solo da `app()->environment(['local','testing'])`, fa `WidgetKey::firstOrCreate` creando una key **attiva** (`pk_demo_local`, `rate_limit 1000`, tenant `default`, project `docs-v3`) e la stampa in pagina. Su box dev/staging condiviso con `APP_ENV=local` e DB reale, un visitatore anonimo conia/legge una credenziale funzionante.

**Fix:**
1. Aggiungi un gate config esplicito `WIDGET_DEMO_ENABLED` (default **false**) oltre all'env, e gate la rotta su entrambi.
2. In alternativa/aggiunta: non coniare nel DB — usa una key effimera in-memory/seeder solo-test, oppure restringi la rotta a `testing` soltanto (come `/testing/*`).
3. Documenta che `/widget-demo` è una superficie dev e non deve essere raggiungibile in staging/prod.

**Verifica:** con `WIDGET_DEMO_ENABLED=false` la rotta 404-a anche in `local`; nessuna key coniata.

---

## FASE 4 — Correttezza (orchestratore, controller, runtime widget)

### #16 DEPTH-RESET — `MAX_AUTO_STEPS` bypassato per BE/host tool (amplificazione costo/DoS)

**Problema:** `MAX_AUTO_STEPS=12` (`bridge.ts`) è controllato **solo dopo** i branch early-return host/BE; `handleBeTool` (no-results, ~315) e `reinjectHostResult` (~421) ricorrono con `this.handle(next, 0)` azzerando la depth. Solo i DOM tool consecutivi sono limitati. Chaining di BE/host tool → ~30-50 chiamate LLM da un singolo messaggio (limitato solo dal cap server `max_steps_per_session`).

**Fix:** mantieni un contatore di profondità **monotòno** lungo l'intera catena: passa `depth + 1` anche in `handleBeTool`/`reinjectHostResult`, e controlla `MAX_AUTO_STEPS` **prima** dei branch host/BE (non solo per i DOM tool). In alternativa usa un contatore d'istanza resettato solo all'input utente.

**Verifica:** test vitest: catena di BE tool che ritornano `has_results=false` → si ferma a `MAX_AUTO_STEPS`, non oltre.

---

### #17 TRANSPORT-NO-TIMEOUT — risposta stallata congela il widget per sempre

**Problema:** nessun `fetch` in `transport.ts` ha `AbortController`/timeout, e `bridge.guard()` tiene `busy=true` finché la promise non si risolve. Una risposta stallata lascia il widget permanentemente non responsivo (ogni messaggio successivo early-return su `isBusy`, nessun errore mostrato).

**Fix:**
1. Aggiungi `AbortController` + timeout configurabile a tutti i `fetch` in `transport.ts` (start/step/exec-tool/setup/cancel/manifest/execHostTool).
2. Su timeout/abort: rigetta la promise, `bridge.guard()` deve resettare `busy=false` nel `finally`, e mostra un errore in UI (panel) con possibilità di ritentare.
3. Esponi un'affordance di **cancel** che abortisce davvero il fetch in volo (oggi `bridge.cancel()` POSTa solo `/cancel` server-side).

**Verifica:** test vitest con fetch che non risolve → dopo il timeout `isBusy()` torna false e l'UI mostra errore.

---

### #18 DUP-LABEL-500 — label duplicata → 500 invece di 422

**Problema:** `WidgetKeyAdminController` store/update (~riga 49) validano `label` senza regola di unicità contro l'indice `uq_widget_keys_tenant_project_label` e non catturano la `QueryException` → una seconda key con stessa `(tenant_id, project_key, label)` → 500 (R14).

**Fix:** aggiungi una regola `Rule::unique('widget_keys')->where('tenant_id', $tenantId)->where('project_key', $projectKey)` (ignorando se stessa in update con `->ignore($id)`). Restituisci 422 con messaggio sul campo `label`. Applica a **store e update**.

**Verifica:** test Feature: creare/rinominare a label duplicata → 422 con errore su `label`, non 500.

---

### #19 EXECTOOL-NO-CAP — `/exec-tool` non è limitato da `max_steps_per_session`

**Problema:** `step()` impone `config('widget.max_steps_per_session')` ma `execTool` no → POST ripetuti a `/exec-tool` fanno crescere `widget_session_steps` illimitatamente (limitato solo dal rate-limit/min), ognuno con una RAG retrieval completa.

**Fix:** applica lo stesso cap in `execTool` (conta gli step della sessione e, oltre il cap, restituisci 422 `session_blocked` portando la sessione a `BLOCKED`, come fa `step()`). Idealmente estrai il check in un metodo privato condiviso.

**Verifica:** test Feature: oltre il cap, `/exec-tool` → 422 e niente nuovi step.

---

### #20 DUP-TOOL-STEP — il tool_call BE è persistito due volte

**Problema:** quando l'LLM emette un BE tool, `WidgetOrchestratorService::finishWithToolCall` persiste già uno step `KIND_TOOL_CALL`; poi `execTool` (~riga 223) ne persiste un **secondo** con lo stesso `kind` prima/dopo l'esecuzione. `buildMessages()` rigioca `[azione] …` due volte nella history LLM e consuma il cap step più in fretta. Inoltre `execTool` persiste **dopo** l'esecuzione (un crash tra exec e create lascia un tool eseguito senza record — ok per search read-only, sbagliato come pattern per futuri tool mutanti).

**Fix:** in `execTool` persisti **solo** lo step `KIND_TOOL_RESULT`; il `KIND_TOOL_CALL` è già scritto dall'orchestratore. Centralizza tutta la scrittura step attraverso `addStep` (o un `WidgetSessionStepRecorder`) per evitare il `max(step_index)+1` duplicato e la race. Per i tool mutanti futuri, persisti il tool_call **prima** dell'esecuzione.

**Verifica:** test: un `search_knowledge_base` round-trip → esattamente 1 `tool_call` + 1 `tool_result`; `buildMessages` non duplica `[azione]`.

---

### #21 SKILL-DIVERGE — `data-skill` diverge dallo skill della sessione

**Problema:** `data-skill` (loader) influenza **solo** la risposta `/setup` (regole annotazione/tools/theme), mentre `start`/`step` usano sempre lo skill memorizzato **sulla key** (transport non trasmette lo skill). La pagina annota con lo skill A mentre l'orchestratore ragiona con lo skill B → host_tools dal manifest sbagliato, silenziosamente.

**Fix (scegli):**
- **(A)** Rendi `data-skill` puramente decorativo: documenta che lo skill **effettivo** è quello della key, e fai sì che `/setup` usi sempre `$key->skill` (rimuovi l'override `?skill=` o rendilo solo per anteprima admin).
- **(B)** Propaga lo skill richiesto: transport invia `skill` in `start`/`step`, l'orchestratore lo valida contro il registry e lo usa per la sessione (con fallback alla key).

**Raccomandato:** (A) per semplicità e sicurezza (un host non sceglie skill arbitrari). Documenta la decisione.

**Verifica:** test che lo skill usato in sessione è coerente con quello mostrato in `/setup`.

---

### #22 IDEMPOTENCY-DEAD — invariante di dedup dichiarata ma mai implementata

**Problema:** `widget_session_steps.idempotency_key` (UNIQUE) + header `X-Widget-Step-Id` (in allowlist CORS) dichiarano un'invariante di dedup-step che **nessun** codice implementa (niente legge l'header, niente scrive la colonna). Un retry FE/network di `/step` rie-esegue un turno LLM a pagamento e duplica gli step (R21: l'invariante è atomica **o assente**; R9: il commento è "fiction" load-bearing).

**Fix (scegli):**
- **(A) Implementa:** in `step()`/`execTool`, leggi `X-Widget-Step-Id`, scrivi `idempotency_key`, e su violazione unique fai early-return restituendo il risultato del turno già processato (dedup vera). Il client (`transport.ts`) deve generare e inviare l'header per i retry.
- **(B) Rimuovi:** elimina la colonna `idempotency_key` dalla migration (è di questa PR, non ancora in prod), l'entry header dalla allowlist CORS, dal `$fillable` del model e il commento — finché non c'è un retry FE reale.

**Raccomandato:** (A) se vuoi davvero la dedup (utile per i turni LLM costosi). Altrimenti (B) per non lasciare invarianti morte.

**Verifica:** se (A): test con due POST `/step` stesso `X-Widget-Step-Id` → un solo turno LLM, un solo step. Se (B): la colonna/commento/header non esistono più.

---

### #24 CORS-DOUBLE-ENGINE — due motori CORS scrivono header in conflitto

**Problema:** l'`HandleCors` globale di Laravel matcha pure `api/widget/*` (config/cors.php `paths` include `api/*`, `supports_credentials=true`), quindi su una richiesta reale del widget **entrambi** i motori scrivono header: il built-in lascia `Access-Control-Allow-Credentials: true` per le origini in `CORS_ALLOWED_ORIGINS` mentre `HandleWidgetCors` riflette l'Origin → combinazione credentialed-reflection che il docblock di `HandleWidgetCors` dichiara di evitare. (Blast radius limitato alle origini già in allowlist statica = origini della SPA.)

**Fix:** escludi il canale widget dal CORS built-in: rimuovi `api/widget/*` dal match di `config/cors.php` (es. restringi `paths` o aggiungi un'esclusione), così solo `HandleWidgetCors` gestisce quelle rotte. In alternativa, fai sì che `HandleWidgetCors` rimuova esplicitamente `Access-Control-Allow-Credentials` sulle risposte widget.

**Verifica:** test Feature: una richiesta reale a `/api/widget/*` da un'origine in `CORS_ALLOWED_ORIGINS` **non** porta `Access-Control-Allow-Credentials: true`.

---

### #37 ISVISIBLE-DEAD — `isVisible()` ritorna sempre true

**Problema:** `executor.ts` `isVisible()` (~riga 167) termina con `|| style != null`, sempre true per elementi attaccati (getComputedStyle ritorna sempre) → i check rect/box/offsetParent sono morti; spotlight/cursor del tour si ancorano a elementi invisibili/zero-size.

**Fix:** rimuovi il disgiunto `|| style != null`. Usa `return rects || box || el.offsetParent !== null;` con il gate display:none/visibility:hidden mantenuto. Se serve il fallback jsdom per i test, rendilo esplicitamente scoped (es. `typeof el.getClientRects !== 'function'`).

**Verifica:** test vitest: elemento figlio di un ancestor `display:none` → `isVisible` false.

---

### #38 WAITFOR-NAN — timeout non clampato / NaN

**Problema:** `executor.ts` `wait_for` (~riga 54) usa `Number(args.timeout_ms ?? 5000)` senza clamp né guard NaN. `timeout_ms=3600000` blocca il widget per un'ora; un valore non numerico → NaN → `Date.now()-start < NaN` sempre false → fallisce subito con messaggio "not met within NaNms".

**Fix:** clampa e guarda:
```ts
let timeoutMs = Number(args.timeout_ms ?? 5000);
if (!Number.isFinite(timeoutMs) || timeoutMs <= 0) timeoutMs = 5000;
timeoutMs = Math.min(timeoutMs, 30_000); // cap superiore
```

**Verifica:** test vitest: `timeout_ms='fast'` → usa 5000; `timeout_ms=3600000` → clampato a 30000.

---

## FASE 5 — Performance & osservabilità

### #25 BUILDMSG-FULL-LOAD — ricarica tutti gli step (con longText) ad ogni turno

**Problema:** `WidgetOrchestratorService::buildMessages()` (~riga 345) fa `$session->steps()->orderBy('step_index')->get()` (tutte le colonne, inclusi i 4 longText snapshot) e poi slice degli ultimi 24 in PHP → O(n²) su una sessione vicina al cap 100. `stepToMessage` legge solo `args_json` — gli snapshot idratati sono spreco puro.

**Fix:** seleziona solo le colonne usate e limita in SQL:
```php
$session->steps()
    ->select(['step_index', 'kind', 'tool', 'args_json'])
    ->orderByDesc('step_index')
    ->limit(self::HISTORY_LIMIT)
    ->get()
    ->reverse();
```

**Verifica:** test che la query non carica i longText; il numero di righe idratate è ≤ `HISTORY_LIMIT`.

---

### #26 LASTUSED-WRITE — UPDATE su `widget_keys` ad ogni richiesta

**Problema:** `ResolveWidgetKey` (~riga 87) fa `forceFill(['last_used_at' => now()])->saveQuietly()` su **ogni** richiesta (inclusi `/setup` e ogni `/step`), contesa su una singola riga hot.

**Fix:** throttle della scrittura: aggiorna `last_used_at` solo se null o più vecchio di ~60s, oppure differiscilo a un terminating callback/queue.
```php
if ($key->last_used_at === null || $key->last_used_at->lt(now()->subSeconds(60))) {
    $key->forceFill(['last_used_at' => now()])->saveQuietly();
}
```

**Verifica:** test che due richieste ravvicinate non producono due UPDATE.

---

### #27 SESSION-LIST-N+1 + per_page illimitato

**Problema:** `WidgetSessionAdminController::index` passa `per_page` dalla request a `paginate()` senza bound, e `serializeList()` (~riga 102) fa `$s->steps->count()` senza eager-load → lazy-load dell'intera collection step (longText) per ognuna delle righe. `?per_page=1000000` → memory exhaustion (R3).

**Fix:**
1. Valida/clampa `per_page` (es. `min(max($perPage, 1), 100)`).
2. Usa `->withCount('steps')` in `index()` e leggi `$s->steps_count` invece di `$s->steps->count()`.
3. In `show()`/`serializeDetail`, vincola il load degli step alle colonne effettivamente emesse.

**Verifica:** test che `?per_page=99999` è clampato; che la lista non lazy-loada gli step (assert sul numero di query, es. con `DB::enableQueryLog`).

---

### #28 MISSING-INDEX — manca l'indice `(tenant_id, created_at)` su `widget_sessions`

**Problema:** l'admin list fa `where('tenant_id', ?)->orderByDesc('created_at')->paginate()`, ma `widget_sessions` ha indici su `tenant_id`, `status`, `(widget_key_id, status)` — non su `(tenant_id, created_at)` → filesort su volumi grandi.

**Fix:** aggiungi `$table->index(['tenant_id', 'created_at'])` nella migration `widget_sessions` di questa PR (modificala direttamente, non è in prod). Verifica gli altri pattern di query (es. prune: `(tenant_id, last_activity_at)` o equivalente — vedi #29).

**Verifica:** la migration applica l'indice; `EXPLAIN` (pgsql) della query admin non fa filesort.

---

### #29 TOKEN-TABLE-UNBOUNDED — `widget_session_tokens` cresce all'infinito

**Problema:** `mint()` inserisce una riga per token ma **niente** cancella i token scaduti/consumati; i token con `widget_session_id = NULL` non cascadano dal prune sessioni. Crescita illimitata + scan su FK non indicizzata (Postgres non indicizza le FK automaticamente).

**Fix:**
1. In `PruneWidgetSessionsCommand`, aggiungi una passata **chunked** che cancella i token scaduti/consumati oltre il cutoff: `WidgetSessionToken::where('expires_at', '<', $cutoff)->...->chunkById(...)->delete()` (R3).
2. Aggiungi un indice su `widget_session_tokens.widget_session_id` (e su `expires_at`) nella migration.
3. Verifica che il prune sessioni cancelli anche gli step via cascade FK (e non one-DELETE-per-row in PHP).

**Verifica:** test del command: token scaduti rimossi a chunk; FK con onDelete cascade verificata (`assertDatabaseMissing`).

---

### #30 CHATLOG-MISSING — il traffico widget è invisibile a Chat Logs/metriche/insights

**Problema:** i turni Q&A del widget persistono **solo** in `widget_session_steps` e non chiamano mai `ChatLogManager::log()` → tab admin Chat Logs, `AdminMetricsService`, `AiInsightsService`, `chat-log:prune` non vedono il traffico widget (canale uncorrelato — altitude).

**Fix:** in `finishWithAnswer` (orchestratore) chiama `ChatLogManager::log()` (contratto never-throw try/catch già pensato per questo) con `extra.channel='widget'`, `session_id=public_session_id`, e i campi che `chat_logs` ha già (question/answer/provider/model/tokens/latency/sources). Gli step restano la traccia agentica; il chat_log è il record analitico unificato.

**Verifica:** test che un turno widget scrive una riga in `chat_logs` con `channel='widget'`; che un fallimento di logging non rompe la risposta (R26/never-throw).

---

## FASE 6 — Admin SPA & coerenza docs

### #31 ROLE-GATE-MISMATCH — la schermata sessioni è super-admin-only ma il BE concede ad admin

**Problema:** `frontend/src/routes/index.tsx` (~riga 704) gata `AdminWidgetRoute` con `RequireRole(['super-admin'])`, ma il gate BE `viewWidgetSessions` (+ matrice di autorizzazione) ammette anche `admin` su `/api/admin/widget-sessions`. Inoltre il nav item "Widget" (`nav-config.ts`) non è role-filtered → ogni ruolo vede un item morto. Risultato: un `admin` è API-autorizzato per le sessioni ma non ha UI per usarle (R32 lato UI).

**Fix:**
1. Decidi la matrice corretta: probabilmente la tab **Keys** resta super-admin (gestione credenziali), la tab **Sessions** è admin+super-admin (read-only). Se così, separa il gating: il route gata sul minimo comune (`['admin','super-admin']`) e il componente nasconde la tab Keys ai non-super-admin.
2. Allinea `AdminAuthorizationMatrixTest` (già fatto in PR? verifica) e `role-access.spec.ts` (aggiungi la riga di reachability UI per il nuovo screen).
3. Considera il filtro nav per ruolo (se il pattern del repo lo prevede; oggi la sidebar non filtra — è un pattern preesistente, ma valuta di non mostrare item irraggiungibili).

**Verifica:** `role-access.spec.ts`: `admin` apre la tab Sessions; `viewer/editor/dpo` non vedono/non raggiungono lo screen secondo la matrice.

---

### #32 MUTATION-NO-ERROR — rotate/revoke/delete senza error surface (R14/R11)

**Problema:** in `WidgetKeysView.tsx` (~riga 229) le mutation `rotateKey`/`revokeKey`/`destroyKey` non renderizzano alcuno stato d'errore (solo create e host-tools hanno l'Alert). Un revoke fallito (403/422/500/network) non mostra nulla → l'operatore crede sia andato a buon fine.

**Fix:** estrai un helper `useWidgetKeyMutation` (o aggiungi `onError` + Alert/Toast condiviso a tutte e 5 le mutation) che fa surface dell'errore in DOM con `data-testid` (R11) e invalida `['admin-widget-keys']`. Usa il `Toast` condiviso esistente (`frontend/src/features/shared/Toast`) per coerenza.

**Verifica:** test vitest (R16): mock di revoke che fallisce → l'errore appare in DOM (`data-testid` dedicato), non silenzioso.

---

### #33 STATUS-FILTER-SUBSET — il filtro stato offre 5 dei 7 stati (R18)

**Problema:** `WidgetSessionsView.tsx` (~riga 114) hardcoda 5 opzioni (active/completed/blocked/aborted/error); il dominio reale ha 7 (`waiting_tool`, `waiting_user` mancanti — vedi `WidgetSession::STATUS_*` e la mappa `STATUS_COLORS` del componente stesso che ne ha 7). Le sessioni bloccate in `waiting_tool` (proprio il caso patologico da indagare) sono non-filtrabili.

**Fix:** deriva le opzioni dalle costanti del dominio (idealmente da un endpoint/`STATUS_COLORS` già presente) e includi tutti e 7 gli stati. No literal subset (R18).

**Verifica:** test vitest: il select contiene `waiting_tool` e `waiting_user`.

---

### #34 CMD-NAME-DRIFT — `widget:issue-secret` vs `widget:emit-secret` (R9/R20)

**Problema:** `EmbedCodeDialog.tsx` (~riga 484) e `frontend/src/widget/README.md` istruiscono a lanciare `php artisan widget:issue-secret`, ma il command registrato è `widget:emit-secret` → `CommandNotFoundException`, il secret non viene mai coniato, proxy mode non configurabile dal path documentato.

**Fix:** allinea il nome del command ovunque al nome reale `widget:emit-secret` (cerca tutte le occorrenze di `widget:issue-secret` in FE/docs/README). In alternativa rinomina il command — ma è più rischioso; preferisci correggere i riferimenti.

**Verifica:** grep `widget:issue-secret` → 0 occorrenze; il command citato esiste (`php artisan list | grep widget`).

---

### #35 THEME-MODE-NOOP — il controllo "Widget type" non ha effetto sui widget già embeddati

**Problema:** il runtime fissa `mode` dalla config di embed e ignora `theme.mode` da `/setup` (`panel.ts` commento "theme.mode server è solo informativo"), ma l'editor Appearance presenta "Widget type" come campo live persistito. Cambiare il tipo e salvare aggiorna l'anteprima/snippet ma **non** i widget già deployati (i colori sì, il layout no).

**Fix (scegli):**
- **(A)** Fai sì che il runtime rispetti `theme.mode` da `/setup` (mode dinamico). Più lavoro, ma il controllo diventa reale.
- **(B)** Rendi "Widget type" un campo **read-only/informativo** nell'Appearance dialog, con nota che il layout è fissato dallo snippet di embed; chiarisci che cambiarlo richiede ri-generare lo snippet.

**Raccomandato:** (B) se il mode deve restare embed-time; altrimenti (A). Documenta.

**Verifica:** se (B): l'UI non lascia credere che il cambio si propaghi; se (A): cambiare mode in admin cambia il layout via `/setup`.

---

### #36 FILTER-KEYID-DEAD — stato del filtro per-key irraggiungibile

**Problema:** `WidgetSessionsView.tsx` (~riga 71) `const [_filterKeyId] = useState<number|null>(null)` è destrutturato senza setter → il filtro per `widget_key_id` (supportato dal BE) è morto dall'UI.

**Fix:** o cabla un controllo UI per impostare `filterKeyId` (es. click sulla colonna "Key" → filtra), o rimuovi lo stato morto se la feature non è richiesta in questa PR.

**Verifica:** se cablato, un test che impostando la key il param `widget_key_id` viene inviato; altrimenti niente dead state.

---

## FASE 7 — Hygiene & coerenza

### #39 BINARY-TEST-FILE — `styles.test.ts` contiene byte NUL grezzi

**Problema:** `frontend/src/widget/ui/styles.test.ts` (~riga 65) contiene byte di controllo grezzi (`0x00`, `0x1F`) dentro una string literal (`'Ask\x00\x1f me'` scritti come byte raw) → git classifica il file come **binario** (diff "Binary files differ"), illeggibile su GitHub, saltato da `grep -I`, e il parsing esbuild/vitest del NUL grezzo è toolchain-dependent.

**Fix:** sostituisci i byte grezzi con escape `\x00\x1f` (o ` `) nella string literal. Il test resta semanticamente identico (verifica la sanitizzazione dei control char) ma il file torna testo.

**Verifica:** `git diff --numstat` mostra righe (non `-\t-\t`); `npm test` passa; il file è leggibile/grep-pabile.

---

### #40 DS-STORE — `.DS_Store` committato

**Problema:** un `.DS_Store` (10KB binario) è committato in root nella stessa PR che edita `.gitignore` senza aggiungere `.DS_Store`.

**Fix:**
1. `git rm --cached .DS_Store` (rimuovi dal tracking).
2. Aggiungi `.DS_Store` (e `**/.DS_Store`) a `.gitignore`.

**Verifica:** `git ls-files | grep DS_Store` → vuoto; il pattern è in `.gitignore`.

---

### #41 ENV-EXAMPLE-DRIFT — env var widget mancanti da `.env.example`/README (R6)

**Problema:** le 5 `WIDGET_*` e `SCHEDULE_WIDGET_PRUNE_SESSIONS_ENABLED`/`_CRON` (lette in `config/askmydocs.php`/`config/widget.php`) non sono in `.env.example` né nel README, mentre ogni slot scheduler gemello è documentato (il messaggio d'errore del registrar lo impone).

**Fix:** aggiungi a `.env.example` (nelle sezioni coerenti con i gemelli) tutte le chiavi nuove: `WIDGET_*` (rate limit, max steps, retention, snapshot caps incl. #23, demo enabled incl. #44) e `SCHEDULE_WIDGET_PRUNE_SESSIONS_ENABLED`/`_CRON`. Aggiorna il quick-start del README se cita knob widget. Assicurati che ogni chiave letta via `config('widget.*')`/`config('askmydocs.*')` sia definita nel rispettivo `config/*.php` **e** in `.env.example` (R6/R9).

**Verifica:** grep di ogni `env('WIDGET_...')`/`env('SCHEDULE_WIDGET_...')` → presente in `.env.example`.

---

### #42 PII-REUSE — `WidgetPiiMasker` reimplementa il redactor invece di riusarlo

**Problema:** il repo integra già `padosoft/laravel-pii-redactor` (^1.2, wired a 4 touch-point host, es. `ChatLogObserver`/`HostIngestionBridge`) con ~16-18 detector (CodiceFiscale, PartitaIva, address, ecc.). `WidgetPiiMasker` è un set regex parallelo più povero → codici fiscali/indirizzi italiani persistono **non mascherati** in `widget_session_steps` mentre `chat_logs` li redige; ogni nuovo detector del package non arriva al widget (drift GDPR per-superficie).

**Fix:** mantieni il wrapper sottile di ricorsione array/JSON di `WidgetPiiMasker`, ma **delega** il masking della singola stringa a `RedactorEngine::redact($s, new MaskStrategy())` (come `HostIngestionBridge`). Le regole di detection vivono in un solo engine. Nota: l'engine host è config-gated (`kb.pii_redactor.enabled`, default false) mentre il masker widget è always-on — decidi la policy (consigliato: always-on per il widget, delegando comunque all'engine per le regole). Rispetta R4 (controlla i return).

**Verifica:** test: una stringa con Codice Fiscale passata al masker widget viene redatta (via engine), non solo email/IBAN.

---

### #43 OBSERVER-DROP — batch di mutazioni persi (DEFERRED: dead code)

**Problema:** `Observer.ts` (~riga 148) ad ogni batch **rimpiazza** `capturedRecords` e resetta il timer di debounce → durante una mutation storm solo l'ultimo batch viene annotato; inoltre i listener focusin/input/change/scroll chiamano `markStale()` non-debounced (per frame di scroll). **MA** `Observer.ts`/`AutoAnnotator.ts` **non sono importati** da nessun file runtime del widget (solo dai loro test) → dead code nel branch attuale.

**Fix:** poiché è dead code, **scegli**:
- **(A)** Se la feature di auto-annotazione è prevista a breve: correggi (accumula i record in un buffer consumato dal timer; debounce i listener via `debouncedMarkStale()`; early-return se già stale) **e** cablalo nel runtime, altrimenti resta non testato in produzione.
- **(B)** Se non è scope di questa PR: marcalo esplicitamente come non-wired (commento/README) o rimuovilo per non spedire dead code; rinvia il fix a quando verrà cablato.

**Raccomandato:** (B) — non spedire dead code non cablato; apri una issue per il wiring + fix insieme.

---

## Nota finale — SHADCN-2ND-DESIGN-SYSTEM (decisione, non bug)

La PR introduce un **secondo design system** (shadcn/Radix: `components.json` + `frontend/src/components/ui/*` + deps `@radix-ui`/`cva`/`clsx`/`tailwind-merge` + `lib/utils.ts cn()`) usato **solo** dalle schermate widget admin, in parallelo al kit hand-rolled usato dalle altre ~25 feature admin (`RoleDialog`, `TagFormDialog`, `shared/Toast`, `components/Icons`, `shell/Tooltip`). Costo: ogni fix di token/focus/a11y va fatto due volte; drift visivo tra tab adiacenti; il prossimo autore deve indovinare quale sistema usare.

**Decisione richiesta (non auto-fixare):** o (a) le schermate widget usano i primitivi condivisi esistenti, o (b) shadcn diventa una migrazione SPA-wide **deliberata con ADR** e piano di conversione. Porta questa scelta a Lorenzo — non risolverla unilateralmente.

---

## Checklist di chiusura (prima del merge)

- [ ] FASE 0–7 completate (o le voci deferred/decisionali esplicitamente tracciate).
- [ ] `vendor/bin/phpunit` (Unit+Feature+Architecture) verde.
- [ ] `npm test` (vitest) verde, inclusi i test corretti per R16 (#1, #32).
- [ ] `npm run build` **e** `npm run build:widget` producono gli artefatti.
- [ ] `bash scripts/verify-e2e-real-data.sh` esce 0.
- [ ] `npm run e2e` verde (nessun timeout sul launcher; nessun `page.route` su rotte interne non marcato).
- [ ] Nuovi/aggiornati test che **provano** i fix di sicurezza (#2, #3, #9, #11) e i degradi OFF-path (#7, #15 — R43).
- [ ] R6: `.env.example` + `config/*.php` + README allineati per ogni nuova env var.
- [ ] R30/R31: `tests/Architecture` verde (modelli widget tenant-scoped).
- [ ] R40: loop critic locale (copilot-cli) → 0 must-fix **prima** del push.
- [ ] R36: PR con `--reviewer copilot-pull-request-reviewer`, loop Copilot cloud + CI verde fino a 0 outstanding.
- [ ] Decisione SHADCN portata a Lorenzo (non auto-risolta).

> **Ordine consigliato di lavoro:** FASE 0 (sblocca CI) → FASE 1 (feature core) → FASE 2 (token) → FASE 3 (sicurezza) → FASE 4 (correttezza) → FASE 5 (perf) → FASE 6 (admin/docs) → FASE 7 (hygiene). Committa per FASE (o per finding) su `feature/kitt-host-tools-foundation`, con messaggi che citano l'ID del finding.
