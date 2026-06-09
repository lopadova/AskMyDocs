# Gruppo "Operations" — pannelli operativi della SPA admin

Il gruppo **Operations** raccoglie gli strumenti di esercizio della piattaforma AskMyDocs: integrazioni esterne (Connectors), pipeline di automazione (Flows), banco di valutazione del retrieval (Eval Harness), esposizione del server MCP `enterprise-kb` (MCP Tools / MCP Tokens), il widget di chat embeddabile (Widget), il visualizzatore di log read-only (Logs) e il runner di comandi Artisan whitelisted con confirm-token (Maintenance). È il gruppo più "infrastrutturale" della console: quasi tutti i pannelli sono riservati a `super-admin`, e i pochi aperti ad `admin` (Logs, Maintenance) restano comunque dietro un permesso server-side.

La navigazione di questo gruppo è definita in `frontend/src/components/shell/nav-config.ts` (gruppo `operations`), le route e i guard RBAC `RequireRole` in `frontend/src/routes/index.tsx`. I dataset di prova citati nei test sono i tre progetti fittizi:

- **rotta-logistics** — logistica e spedizioni;
- **prometeo-antincendio** — consulenza normativa antincendio / vigili del fuoco;
- **passolibero-calzature** — vendita scarpe.

> Nota trasversale sull'isolamento: tutti gli endpoint admin di questo gruppo sono **tenant-scoped** lato backend (`forTenant(TenantContext::current())` o `where('tenant_id', …)`, regola R30). I pannelli per-progetto (Logs, Widget, parte di MCP) filtrano inoltre per `project_key`. Dove un pannello accetta un filtro progetto, l'isolamento va verificato esplicitamente come indicato nelle sezioni seguenti.

---

## Connectors

### Percorso
- **Route SPA**: `/app/admin/connectors` (callback OAuth: `/app/admin/connectors/$key/callback`).
- **Sidebar**: gruppo **Operations** → voce **Connectors** (icona `Link`).
- **Componente**: `frontend/src/features/admin/connectors/ConnectorsView.tsx`.

### Ruoli
Solo **super-admin**. Il guard FE è `<RequireRole roles={['super-admin']}>` (`AdminConnectorsRoute` in `routes/index.tsx`); la difesa autorevole è il Gate BE `manageConnectors` (super-admin). Gli altri ruoli vedono `<AdminForbidden />`.

### A cosa serve
Collega sorgenti esterne (es. Google Drive, Notion) via OAuth e ne sincronizza il contenuto dentro la knowledge base del tenant. Ogni connettore registrato è una "carta" con il suo ciclo di vita: installa (consenso OAuth) → attivo → sync periodico/manuale → disabilita o disconnetti. Permette di portare documentazione viva da repository esterni senza upload manuale.

### Cosa vedi nella pagina
- Titolo **Connectors** e sottotitolo "Connect external sources via OAuth and sync their content into your knowledge base."
- Una **griglia di card** (`admin-connectors-grid`), una per connettore registrato (`ConnectorCard`), con stato dell'installazione e i pulsanti **Connect** (avvia OAuth), **Sync now**, **Disconnect**, ed eventuale **Cancel install**.
- Stati osservabili sul contenitore `admin-connectors` via `data-state`: `loading` (`admin-connectors-loading`), `error` (`admin-connectors-error` con pulsante **Retry**), `empty` (`admin-connectors-empty`, "No connectors are registered in this AskMyDocs build."), `ready`.
- Toast deterministici per ogni esito mutazione: `toast-connector-error`, `toast-connector-synced` ("Sync queued."), `toast-connector-disconnected`.

### Dati / endpoint
Backend `app/Http/Controllers/Api/Admin/ConnectorAdminController.php`, hooks FE in `connectors-hooks.ts`:
- `GET /api/admin/connectors` — lista connettori + stato installazione del tenant attivo (null se non installato).
- `GET /api/admin/connectors/{name}/install` — pre-crea la riga `pending` e ritorna `redirect_to` (URL OAuth del provider); il browser ci naviga con `window.location.assign`.
- `GET /api/admin/connectors/{name}/oauth/callback` — completa l'OAuth e porta l'installazione ad `active`.
- `POST /api/admin/connectors/{installationId}/sync-now` — accoda un `ConnectorSyncJob` (HTTP 202).
- `POST /api/admin/connectors/{installationId}/disable` — mette in pausa il sync senza revocare le credenziali.
- `DELETE /api/admin/connectors/{installationId}` — disconnette (revoca upstream best-effort) e rimuove la riga (204).

Tutte le query su `connector_installations` sono scoped per `tenant_id` (R30).

### Come testarlo con i 3 dataset
1. Entra come **super-admin** e apri `/app/admin/connectors`. Se nessuna installazione esiste, le card mostrano lo stato "non installato" con il pulsante **Connect**.
2. Per **rotta-logistics**: avvia l'installazione di un connettore (es. Google Drive) e completa il consenso OAuth → la card deve passare a `active` e mostrare **Sync now** / **Disconnect**. Premi **Sync now** → toast `toast-connector-synced` e un `ConnectorSyncJob` in coda che porta i documenti nel progetto.
3. Ripeti per **prometeo-antincendio** e **passolibero-calzature** con sorgenti distinte.
4. **Check di isolamento (tenant)**: l'installazione è legata al `tenant_id` attivo. Operando come tenant del progetto antincendio, la lista NON deve mostrare le installazioni create per logistica o calzature (e viceversa). I documenti importati da un sync devono finire solo nella KB del progetto del connettore, mai negli altri.
5. **Errore**: simula un fallimento (es. consenso negato) → lo stato resta `pending`/error e il pulsante **Retry**/install resta disponibile, senza toast di successo.

---

## Flows

### Percorso
- **Route SPA**: `/app/admin/flows`.
- **Sidebar**: gruppo **Operations** → voce **Flows** (icona `Bolt`).
- **Componente**: `frontend/src/features/admin/flows/FlowsView.tsx`.

### Ruoli
**admin**, **super-admin**, **dpo**. Guard FE `<RequireRole roles={['admin','super-admin','dpo']}>`; il Gate BE `viewFlowAdmin` e il middleware `flow-admin.enabled` sono la difesa autorevole (403 se non autorizzato, 404 se la feature è disattivata).

### A cosa serve
Pagina-ponte verso il cockpit del pacchetto `padosoft/laravel-flow-admin`: il motore di pipeline/automazioni (run, approvazioni, webhook outbox, definizioni). Il cockpit vero è un'app Blade+Alpine con chrome proprio e si apre **standalone in una nuova scheda**; questa pagina nativa mostra solo le KPI live e i link rapidi alle sezioni, così l'operatore ha un colpo d'occhio sul throughput senza lasciare AskMyDocs.

### Cosa vedi nella pagina
- Header **Flows** con badge `padosoft/laravel-flow-admin` e bottone **Open Flow cockpit** (`admin-flows-open-cockpit`, apre `/admin/flows` in `target=_blank`).
- Tre **KPI** (`role="list"` "Flow throughput"): **Total runs** (`admin-flows-kpi-total`), **Succeeded** (`admin-flows-kpi-succeeded`), **Failed** (`admin-flows-kpi-failed`).
- Sezione **Cockpit sections** con link rapidi (`admin-flows-section-*`): Overview, Runs, Approvals, Outbox, Definitions, Settings — ciascuno apre la sezione del cockpit in nuova scheda.
- Stati su `admin-flows-host` via `data-state`: `loading` (`admin-flows-loading`), `error` (`admin-flows-error` con suggerimento di impostare `FLOW_ADMIN_ENABLED=true`), `ready`.

### Dati / endpoint
- `GET /admin/flows/api/live` — KPI live (`{ totalRuns, failedRuns }`); il componente valida che entrambi i contatori siano numeri finiti (R14) prima di mostrarli, altrimenti va in `error`. `Succeeded` è derivato come `max(0, totalRuns - failedRuns)`.
- `/admin/flows` (+ sotto-path `/runs`, `/approvals`, `/outbox`, `/definitions`, `/settings`) — cockpit del pacchetto, aperto in nuova scheda.

### Come testarlo con i 3 dataset
1. Con `FLOW_ADMIN_ENABLED=true`, apri `/app/admin/flows` come admin/super-admin/dpo: le tre KPI devono popolarsi da `/admin/flows/api/live` e lo stato diventare `ready`.
2. Clicca **Open Flow cockpit** → si apre il cockpit standalone in una nuova scheda; verifica che le sezioni (Runs, Approvals, …) siano raggiungibili dai link rapidi.
3. **Feature flag OFF (R43)**: con `FLOW_ADMIN_ENABLED=false` (e `php artisan config:clear`) la pagina deve degradare in modo pulito allo stato `error` con il messaggio "The Flow cockpit is unavailable", **mai** un 500 o un crash.
4. **Isolamento**: le KPI di throughput sono globali sul motore di flow del deployment, non per-progetto, quindi qui non si applica un check `project_key`. Le pipeline che agiscono sulla KB restano comunque tenant-scoped lato motore.

---

## Eval Harness

### Percorso
- **Route SPA**: `/app/admin/eval-harness` (+ splat `/app/admin/eval-harness/$` per le 8 sotto-pagine interne).
- **Sidebar**: gruppo **Operations** → voce **Eval Harness** (icona `Brain`).
- **Componente**: `frontend/src/features/admin/eval-harness/EvalHarnessView.tsx`.

### Ruoli
**admin**, **super-admin**, **dpo**, **editor**. Guard FE `<RequireRole roles={['admin','super-admin','dpo','editor']}>`; difesa BE: Gate `eval-harness.viewer` + middleware `eval-harness-ui.non-prod` (404 in produzione) + check `eval-harness-ui.enabled` del pacchetto (404 se env=false).

### A cosa serve
Banco di valutazione del retrieval/RAG: è il cross-mount della SPA `padosoft/eval-harness-ui`. Permette di lanciare run di valutazione su dataset di domande, confrontare report, vedere trend e prove avversariali (adversarial) per misurare regressioni nella qualità delle risposte e del retrieval prima di rilasciare modifiche alla pipeline.

### Cosa vedi nella pagina
- La SPA del pacchetto montata in cross-mount (`data-mount="cross-mount"`) con le sue 8 sotto-pagine via `BrowserRouter` interno: **Dashboard, Reports, ReportDetail, Compare, Trend, Adversarial, AdversarialDetail, LiveBatches**.
- Stati sul contenitore `admin-eval-harness-host` via `data-state`:
  - `loading` (`admin-eval-harness-loading`, "Loading Eval Harness…");
  - `unavailable` (`admin-eval-harness-unavailable`): landing pulito "Eval Harness data API is not available" quando le rotte dati del pacchetto non rispondono;
  - `ready`: la dashboard del pacchetto.

### Dati / endpoint
- `GET /api/admin/eval-harness/bootstrap-config` — config host della SPA (metric labels, polling, locale, shortcuts); endpoint gated `auth:sanctum` + `can:eval-harness.viewer` (`EvalHarnessUiBootstrapController`). In caso di fallimento si usa un `FALLBACK_CONFIG` degradato.
- `GET /admin/eval-harness/api/reports` — **probe** del data backend reale eseguito al mount: se non risponde, la pagina mostra `unavailable` invece di una tempesta di error panel (R14).
- `/admin/eval-harness/api/*` — rotte dati del pacchetto (`EVAL_HARNESS_API_BASE`).

### Come testarlo con i 3 dataset
1. Con `EVAL_HARNESS_UI_ENABLED=true` e backend dati cablato, apri `/app/admin/eval-harness` come uno dei ruoli ammessi: deve raggiungere lo stato `ready` e mostrare la Dashboard.
2. Naviga le sotto-pagine **Reports** e **Compare** per ispezionare i run di valutazione del retrieval sui dataset (le domande/golden answers usate per logistica, antincendio, calzature).
3. **Feature flag / backend non cablato (R43)**: con `EVAL_HARNESS_UI_ENABLED=false` o senza data API, la pagina deve mostrare lo stato `unavailable` pulito, **mai** un 500 grezzo. In produzione le rotte rispondono 404 by design.
4. **Isolamento**: i report sono associati al tenant; assicurati che i run/report visibili siano quelli del tenant attivo e che i set di domande di un progetto non compaiano nei report di un altro.

---

## MCP Tools

### Percorso
- **Route SPA**: `/app/admin/mcp-tools`.
- **Sidebar**: gruppo **Operations** → voce **MCP Tools** (icona `Terminal`).
- **Componente**: `frontend/src/features/admin/mcp-tools/McpToolsView.tsx`.

### Ruoli
Solo **super-admin**. Guard FE `<RequireRole roles={['super-admin']}>`; Gate BE autorevole `manageMcpTools` (super-admin).

### A cosa serve
Control plane per i **server MCP** registrati dal tenant: AskMyDocs espone il proprio server `enterprise-kb` (i 10 tool: 5 retrieval + 5 canonical/promote) e può registrare server MCP esterni (stdio/SSE/HTTP). Da qui un operatore registra un server, esegue l'handshake per scoprirne i tool, abilita/disabilita i singoli tool e disattiva o elimina la registrazione. Include un audit delle chiamate ai tool.

### Cosa vedi nella pagina
- Titolo **MCP tools** e due tab (`role="tablist"`): **Servers** (`admin-mcp-tools-tab-servers`) e **Audit log** (`admin-mcp-tools-tab-audit`).
- Sul tab **Servers**: pulsante **+ Register server** (`admin-mcp-tools-register`, apre `RegisterServerDialog`) e l'elenco card (`admin-mcp-servers`) con stati `loading` / `error` (`admin-mcp-servers-error`) / `empty` (`admin-mcp-servers-empty`, "No MCP servers registered yet.") / `ready`.
- Ogni **card server** (`admin-mcp-server-{id}`) mostra nome, `transport · endpoint`, lo stato handshake (`HandshakeStatus` con retry), la **matrice tool** (`ToolMatrix`, abilita/disabilita e **Save**), e i pulsanti **Disable** (`…-disable`) e **Delete** (`…-delete`, con `window.confirm`).
- Sul tab **Audit log**: la vista `McpToolCallAuditView` (audit paginato delle chiamate ai tool con filtri).

### Dati / endpoint
Backend `app/Http/Controllers/Api/Admin/McpServersAdminController.php` (+ `McpToolCallAuditController`):
- `GET /api/admin/mcp-servers` — lista server del tenant.
- `POST /api/admin/mcp-servers` — registra un server (`name`, `transport` in `stdio|sse|http`, `endpoint`, `auth_config`, `enabled_tools`; stato iniziale `pending`).
- `POST /api/admin/mcp-servers/{id}/handshake` — esegue l'handshake (502 + stato `errored` se fallisce).
- `PUT /api/admin/mcp-servers/{id}/tools` — aggiorna i tool abilitati.
- `POST /api/admin/mcp-servers/{id}/disable` — disattiva.
- `DELETE /api/admin/mcp-servers/{id}` — elimina (204).
- `GET /api/admin/mcp-tool-call-audit` — audit delle chiamate.

Tutte le query su `mcp_servers` sono scoped per `tenant_id`.

### Come testarlo con i 3 dataset
1. Come **super-admin**, apri `/app/admin/mcp-tools`. Se nessun server è registrato, vedi l'empty state.
2. **+ Register server**: registra un endpoint MCP (es. il server `enterprise-kb` interno o un server di test) → la card appare con stato `pending`.
3. Premi handshake → lo stato deve aggiornarsi e la **ToolMatrix** popolarsi con i tool scoperti; abilita un sottoinsieme e **Save**.
4. Apri il tab **Audit log** e verifica che le chiamate ai tool vengano tracciate.
5. **Check di isolamento (tenant)**: i server registrati sono legati al `tenant_id`. Un server registrato dal tenant di logistica NON deve comparire nella lista del tenant antincendio o calzature; lo stesso vale per le righe di audit delle chiamate ai tool.
6. **Delete**: conferma il `window.confirm` → la card sparisce (204).

---

## MCP Tokens

### Percorso
- **Route SPA**: `/app/admin/mcp/tokens`.
- **Sidebar**: gruppo **Operations** → voce **MCP Tokens** (icona `Command`).
- **Componente**: `frontend/src/features/admin/mcp-tokens/McpTokensView.tsx` (montato in `AdminShell section="mcp-tokens"`).

### Ruoli
Solo **super-admin**. Guard FE `<RequireRole roles={['super-admin']}>`.

### A cosa serve
Conia e revoca i **token di accesso MCP per consumer esterni** del tenant (es. un agente CI che parla con il server `enterprise-kb`). Il token in chiaro è mostrato **una volta sola** alla creazione; in lista resta solo l'ultima parte (`••••last4`) e gli scope. Serve a dare/togliere accesso programmato alla KB via MCP senza distribuire credenziali utente.

### Cosa vedi nella pagina
- Titolo **MCP Tenant Tokens** e sottotitolo sul minting/revoca.
- Riga di creazione: input **label** (`admin-mcp-tokens-label`, placeholder "Token label (e.g. CI Agent)") + pulsante **Mint token** (`admin-mcp-tokens-mint`, disabilitato se label vuota).
- Box one-time del token in chiaro (`admin-mcp-tokens-plain`): "Copy now:" con il `plain_token` — visibile solo subito dopo il mint.
- Elenco token (`admin-mcp-tokens-row-{id}`): label, `••••{last4}`, scope; sulla destra pulsante **Revoke** (`admin-mcp-tokens-revoke-{id}`) oppure l'etichetta **Revoked** (`admin-mcp-tokens-revoked-{id}`) per i token già revocati.

### Dati / endpoint
Backend `app/Http/Controllers/Api/Admin/McpTenantTokenController.php`:
- `GET /api/admin/mcp/tokens` — lista token del tenant (mai il token in chiaro).
- `POST /api/admin/mcp/tokens` — crea un token (`label`, opzionali `expires_at`, `scopes`; default scopes `mcp:read`, `mcp:tools:propose`). Ritorna `plain_token` una sola volta.
- `POST /api/admin/mcp/tokens/{id}/revoke` — revoca (idempotente: imposta `revoked_at` se non già revocato).

Query scoped per `tenant_id`.

### Come testarlo con i 3 dataset
1. Come **super-admin**, apri `/app/admin/mcp/tokens`. Inserisci una label (es. "CI Logistica") e **Mint token** → compare il box one-time con il token in chiaro; copialo subito.
2. Verifica che la riga in lista mostri solo `••••last4` e gli scope, mai il token completo.
3. Premi **Revoke** su un token → la riga deve passare a **Revoked** e l'endpoint deve restare idempotente su un secondo tentativo.
4. **Check di isolamento (tenant)**: conia token per tenant differenti (logistica, antincendio, calzature). La lista di un tenant NON deve mostrare i token coniati per un altro tenant; un token revocato in un tenant non influenza gli altri.

---

## Widget

### Percorso
- **Route SPA**: `/app/admin/widget`.
- **Sidebar**: gruppo **Operations** → voce **Widget** (icona `Chat`).
- **Componente**: `frontend/src/features/admin/widget/WidgetAdminView.tsx` (montato in `AdminShell section="widget"`).

### Ruoli
Solo **super-admin**. Guard FE `<RequireRole roles={['super-admin']}>`.

### A cosa serve
Amministra il **widget di chat embeddabile KITT**: l'AI assistant che si incolla su un sito esterno con uno `<script>` e risponde grounded sulla KB di un progetto. Da qui si creano/ruotano/revocano le credenziali del widget, si ispezionano le sessioni dei visitatori e si ottiene la guida d'integrazione (incluso il prompt per annotare il DOM con il contratto `data-kitt-*`).

### Cosa vedi nella pagina
Container `admin-widget-view` con tre tab (`role="tablist"`, `admin-widget-tabs`): **Widget Keys** (`admin-widget-tab-keys`), **Sessions** (`admin-widget-tab-sessions`), **Integration** (`admin-widget-tab-guide`).

- **Widget Keys** (`WidgetKeysView`): pulsante **Create Key** (`admin-widget-keys-create-btn`) con form (tipo widget *helper*/*inline*, label, **project key**, allowed origins, rate limit, skill, toggle **host tools**). Box one-time delle credenziali (`pk_…` pubblica + `sk_…` segreta, `admin-widget-keys-created-creds` / `…-rotated-creds`) con **Get embed code**. Tabella chiavi (`admin-widget-keys-table`) con colonne Label / Public Key / Project / Mode / Origins / Rate / Host tools / Status / Sessions / Last Used e azioni per riga: **Embed**, **Appearance**, **Origins**, **Rotate**, **Revoke**, **Delete**. Stati `loading` / `error` / `empty`.
- **Sessions** (`WidgetSessionsView`): filtro per **status** (`admin-widget-sessions-filter-status`: active/completed/blocked/aborted/error), tabella sessioni (`admin-widget-sessions-table`) con Key / Status / Skill / Mission / Origin / Steps / Created, paginazione, e pannello di dettaglio espandibile (`admin-widget-session-detail`) con gli step (kind, tool, token in/out, latenza) e l'eventuale `blocked_reason`.
- **Integration** (`WidgetIntegrationGuideView`): mini-guida "Make KITT smarter on your page", il **hand-off command** copiabile (`admin-widget-guide-handoff-copy`) da dare a un agente AI per annotare il DOM, e la cheat-sheet degli attributi `data-kitt-*`.

### Dati / endpoint
Backend `WidgetKeyAdminController.php` e `WidgetSessionAdminController.php`:
- `GET/POST /api/admin/widget-keys`, `PATCH/DELETE /api/admin/widget-keys/{id}`, `POST /api/admin/widget-keys/{id}/rotate`, `POST /api/admin/widget-keys/{id}/revoke` — gestione chiavi (creazione ritorna `plain_secret` + `public_key` una volta sola).
- `GET /api/admin/widget-sessions` (filtro `status`, `widget_key_id`) e `GET /api/admin/widget-sessions/{id}` — lista/dettaglio sessioni.
- Lo `<script>` runtime del widget e l'API di chat del widget vivono fuori dallo stack `auth:sanctum`, governati da `widget.key` + CORS per-origin.

### Come testarlo con i 3 dataset
1. Come **super-admin**, tab **Widget Keys** → **Create Key**: imposta **project key = `passolibero-calzature`**, scegli tipo *helper*, aggiungi un allowed origin (es. `https://shop.example.com`) e crea. Copia subito `sk_…` dal box one-time, poi **Get embed code** per lo snippet `<script>`.
2. Ripeti creando una chiave per **rotta-logistics** e una per **prometeo-antincendio**, ciascuna sul proprio project key.
3. Verifica in tabella che ogni chiave mostri il proprio Project, Mode e Status; prova **Rotate** (conferma) e controlla che compaia il box con il nuovo segreto; prova **Revoke** → Status passa a *Revoked*.
4. Tab **Sessions**: dopo qualche interazione del widget, filtra per status e apri una riga → il dettaglio mostra mission, page URL e gli step.
5. **Check di isolamento (per progetto)**: una chiave legata a `passolibero-calzature` deve far rispondere il widget **solo** sulla KB delle calzature — le citazioni non devono mai includere documenti di logistica o antincendio. Nelle Sessions, le sessioni elencate sono quelle del tenant attivo; verifica che le sessioni generate da una chiave di un progetto non si mescolino con quelle di un altro.

---

## Logs

### Percorso
- **Route SPA**: `/app/admin/logs` (deep-link tab via `?tab=`; alias `/app/logs`).
- **Sidebar**: gruppo **Operations** → voce **Logs** (icona `Activity`).
- **Componente**: `frontend/src/features/admin/logs/LogsView.tsx` (montato in `AdminShell section="logs"`).

### Ruoli
**admin**, **super-admin**. Guard FE `<RequireRole roles={['admin','super-admin']}>` (`AdminLogsRoute`); RBAC BE via middleware `role:admin|super-admin`.

### A cosa serve
Visualizzatore **read-only** della telemetria di esercizio: traffico di chat, trail editoriale canonico, coda del log applicativo, activity log e job falliti. Serve a diagnosticare problemi (latenze, modelli usati, errori di ingest/promote, job in coda morti) senza dare accesso a operazioni distruttive — quelle vivono in Maintenance.

### Cosa vedi nella pagina
Titolo **Logs** e cinque tab (`logs-tab-*`), con pannello attivo `logs-panel-{tab}`:

1. **Chat Logs** (`logs-tab-chat`, `ChatLogsTab`) — barra filtri (Project, Model, Min latency ms, Min tokens, From, To), tabella `chat-logs-table` (#, Project, Model, Question, Tokens, Latency, When), paginazione e **drawer** di dettaglio (`chat-log-drawer`) con provider/model, token prompt/completion/total, latenza, domanda, risposta, citazioni.
2. **Canonical Audit** (`logs-tab-audit`, `AuditTab`) — trail editoriale `kb_canonical_audit` (event_type promoted/updated/deprecated/superseded/…), filtrabile per project/event/actor/date.
3. **Application** (`logs-tab-app`, `ApplicationLogTab`) — tail del file di log applicativo (max 2000 righe), con filtro livello.
4. **Activity** (`logs-tab-activity`, `ActivityTab`) — Spatie activity log (degrada con nota se la tabella non è installata).
5. **Failed Jobs** (`logs-tab-failed`, `FailedJobsTab`) — tabella `failed_jobs` (read-only; retry/forget non sono qui ma in Maintenance).

Ogni tab espone stati `data-state` `loading` / `ready` / `empty` / `error`.

### Dati / endpoint
Backend `app/Http/Controllers/Api/Admin/LogViewerController.php`:
- `GET /api/admin/logs/chat` (filtri project/model/min_latency_ms/min_tokens/from/to, push in SQL) e `GET /api/admin/logs/chat/{id}`.
- `GET /api/admin/logs/canonical-audit` (filtri project/event_type/actor/from/to).
- `GET /api/admin/logs/application?file=…&level=…&tail=…` — error matrix: 422 filename non valido, 404 file mancante, 500 file illeggibile (R14).
- `GET /api/admin/logs/activity`.
- `GET /api/admin/logs/failed-jobs`.
- (Anche `POST /api/admin/logs/chat/{id}/detokenize` per il round-trip PII, gated dal permesso `pii.detokenize`.)

`chat_logs`, `kb_canonical_audit` e gli altri sono **tenant-scoped** (`forTenant`, R30); l'IDOR su `chat/{id}` è bloccato dallo stesso scope.

### Come testarlo con i 3 dataset
1. Come **admin**, apri `/app/admin/logs?tab=chat`. Nel filtro **Project** inserisci `rotta-logistics` → la tabella deve mostrare solo le chat di logistica.
2. Cambia il filtro Project in `prometeo-antincendio` e poi `passolibero-calzature` e verifica che il set di righe cambi di conseguenza.
3. **Check di isolamento (per progetto)**: filtrando su un progetto NON devono comparire domande/risposte di un altro; svuotando il filtro, vedi solo i log del **tenant attivo** (mai quelli di un altro tenant). Apri il drawer di una riga e conferma che domanda/risposta/citazioni appartengano al progetto atteso.
4. Tab **Canonical Audit**: filtra per `project = prometeo-antincendio` e verifica che gli eventi (promoted/updated/…) siano solo del progetto antincendio.
5. Tab **Application**: chiedi un file inesistente → la UI deve gestire 404 (non un 200 vuoto). Tab **Failed Jobs**: verifica che la lista sia read-only.

---

## Maintenance

### Percorso
- **Route SPA**: `/app/admin/maintenance` (alias `/app/maintenance`).
- **Sidebar**: gruppo **Operations** → voce **Maintenance** (icona `Wrench`).
- **Componente**: `frontend/src/features/admin/maintenance/MaintenanceView.tsx` (montato in `AdminShell section="maintenance"`).

### Ruoli
**admin**, **super-admin**. Guard FE `<RequireRole roles={['admin','super-admin']}>` (`AdminMaintenanceRoute`). Attenzione: il **catalogo è filtrato per permesso lato server**, quindi i comandi distruttivi (che richiedono `commands.destructive`) non compaiono affatto a chi non ha quel permesso — "ciò che non puoi eseguire, non lo vedi".

### A cosa serve
Runner di **comandi Artisan whitelisted** dalla console: l'operatore lancia manutenzioni (validate canonical, rebuild graph, retry queue, ingest/delete KB, prune di retention) tramite un wizard guidato, con auditing completo e — per i comandi **distruttivi** — un **confirm-token** monouso e una conferma "type-to-confirm". Mostra anche lo stato dello scheduler e lo storico delle esecuzioni.

### Cosa vedi nella pagina
Titolo **Maintenance** ("Whitelisted artisan commands — run, audit, schedule.") e due tab: **Commands** (`maintenance-tab-commands`) e **History** (`maintenance-tab-history`).

- **Commands** (`maintenance-panel-commands`): griglia di card (`CommandCard`) raggruppate per categoria — **Knowledge base** (`maintenance-category-kb-content`), **Retention / pruning** (`maintenance-category-pruning`), **Queue** (`maintenance-category-queue`), **Other**. A destra la **SchedulerStatusCard** (slot pianificati con cron). Stati su `data-state` (`loading`/`ready`/`error`, con `maintenance-catalogue-error`).
- **Command Wizard** (`command-wizard`, aprendo una card) — tre/quattro step (`data-step`):
  1. **Preview** (`wizard-step-preview`): form generato da `args_schema`, pulsante **Preview**. Per i distruttivi la risposta porta un `confirm_token`.
  2. **Confirm** (`wizard-step-confirm`, solo distruttivi): badge **DESTRUCTIVE** e input "type-to-confirm" (`wizard-confirm-input`) in cui digitare esattamente il nome del comando; il pulsante **Run** (`wizard-confirm-continue`) si abilita solo se il testo combacia.
  3. **Run** (`wizard-run`): esegue e fa polling della history sull'`audit_id`.
  4. **Result** (`wizard-result`): Status, Exit code, `wizard-stdout` (testa dell'output) o `wizard-error`.
- **History** (`maintenance-panel-history`, `CommandHistoryTable`): righe audit `AdminCommandAudit` con status `started/completed/failed/rejected`, filtrabili e paginate.

### Dati / endpoint
Backend `app/Http/Controllers/Api/Admin/MaintenanceCommandController.php`; whitelist in `config/admin.php` (`allowed_commands`):
- `GET /api/admin/commands/catalogue` — comandi consentiti al caller (con `args_schema`, `destructive`, `requires_permission`).
- `POST /api/admin/commands/preview` — valida; per i distruttivi ritorna `confirm_token` (TTL 5 min, monouso).
- `POST /api/admin/commands/run` — esegue con `confirm_token`; rate-limited (`throttle:3,5`). Scrive sempre una riga di audit (anche per i rifiuti). Matrice esiti: 404 unknown, 403 forbidden, 422 schema/token, 500 fallimento esecuzione.
- `GET /api/admin/commands/history` — audit paginato (tenant-scoped, R30).
- `GET /api/admin/commands/scheduler-status` — slot pianificati effettivi (cron + descrizione).

Esempi di comandi whitelisted: non distruttivi `kb:validate-canonical`, `kb:rebuild-graph`, `queue:retry`; distruttivi (richiedono `commands.destructive` + confirm-token) `kb:ingest-folder`, `kb:delete`, `kb:prune-deleted`, `kb:prune-embedding-cache`, `kb:prune-orphan-files`, `chat-log:prune`, `activity-log:prune`.

### Come testarlo con i 3 dataset
1. Come **admin/super-admin**, apri `/app/admin/maintenance` (tab **Commands**): verifica che le card siano raggruppate per categoria e che lo SchedulerStatusCard mostri gli slot cron.
2. **Comando non distruttivo per progetto**: apri `kb:validate-canonical`, nel Preview imposta l'argomento `project = rotta-logistics` e lancia → il Result deve mostrare Status `completed` e l'stdout della validazione **solo** per il progetto logistica. Ripeti con `project = prometeo-antincendio` e `project = passolibero-calzature` (es. `kb:rebuild-graph`) verificando che ogni run agisca sul progetto indicato.
3. **Comando distruttivo + confirm-token**: apri `kb:delete` (badge DESTRUCTIVE). Nel Preview imposta `project = passolibero-calzature` e `path` di un documento; dopo Preview il wizard passa allo step **Confirm** dove devi digitare esattamente `kb:delete` per abilitare **Run**. Esegui e controlla nello stato **Result** l'esito; poi nella tab **History** deve comparire la riga di audit.
4. **Check di isolamento (per progetto)**: un `kb:delete`/`kb:ingest-folder` lanciato con `project = passolibero-calzature` non deve toccare i documenti di logistica o antincendio; un `kb:validate-canonical` con un progetto deve riportare conteggi solo di quel progetto. In **History**, le righe visibili sono quelle del **tenant attivo** (R30): un altro tenant non deve vedere il tuo audit.
5. **Sicurezza del token (R21)**: il `confirm_token` è monouso e scade in 5 minuti — un secondo `Run` con lo stesso token deve fallire (422), e un comando distruttivo senza token valido deve essere rifiutato.
