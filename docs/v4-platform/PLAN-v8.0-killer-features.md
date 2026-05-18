# Plan — AskMyDocs v8.0 — Killer Features Distintive

> **Context.** Sessione brainstorming originale: `C:\Users\lopad\Documents\DocLore\obsidianlore\obsidianlore\Clippings\AskMyDocs V8.0 Killer features distintive.md` (10 idee killer + sezioni A/B/C/D/E). Lorenzo applica ora 10 osservazioni/correzioni e chiede roadmap definitiva con ADR + task atomiche + acceptance gate per ogni feature, più recap di cosa va in `askmydocs-pro` (privato a pagamento).
>
> **Stato corrente del repo (verificato 2026-05-18).**
> - Notifiche nel host AskMyDocs: **assenti** (zero migration / model / controller / bell / canale). `bootstrap/app.php` linee 168–172 ha placeholder commento per `notifications:prune` non wirato.
> - Scheduler: tutti i 12 slot hanno cron hard-coded in `bootstrap/app.php`; solo le retention window sono env-configurabili.
> - Cicli predecessori chiusi: v7.0.0 GA `2026-05-16` (mcp-pack host integration) + v7.1.0 GA `2026-05-18 08:54Z` (mcp-pack v1.5 + mcp-pack-admin v1.1 live wire-up — semver MINOR sopra v7.0). Le feature qui pianificate aprono il **nuovo ciclo major v8.0**, cut subito dopo v7.1.0.
>
> **Cycle label.** v8.0 = next major (semver) post v7.1.0. Originariamente il plan fu scritto come "v9.0" sull'assunto che il wire-up mcp-pack del 2026-05-18 sarebbe stato un major bump; è uscito invece come v7.1 minor, quindi il next major è v8.0. Tutti i riferimenti sono stati riallineati 2026-05-18.

---

## §A — Risposte puntuali alle 10 osservazioni

### A1. Formula health_score con pesi configurabili
**ACK.** I pesi della formula `health_score` vivono in `config/askmydocs.php` sotto `kb_health.weights` (5 chiavi: `age_decay`, `repeat_questions`, `supersedes_chain`, `orphan_outbound`, `status_decay`). Per-tenant override via nuova tabella `tenant_settings(tenant_id, namespace, key, value_json)` letta da `KbHealthService::weights($tenantId)` con fallback config globale. UI in `/admin/kb/health/settings` (sliders + "preview" che mostra come cambia il top-20 al variare dei pesi prima di salvare).

### A2. Scheduler tunabile per slot
**Decisione.** Due tier:
- **Tier 1 — per-host env config (v8.0/W1).** Ogni slot in `bootstrap/app.php` legge cron da config:
  ```php
  Schedule::command('kb:prune-deleted')
      ->cron(config('askmydocs.schedule.kb_prune_deleted.cron', '30 3 * * *'))
      ->when(fn () => config('askmydocs.schedule.kb_prune_deleted.enabled', true))
      ->onOneServer()->withoutOverlapping();
  ```
  Env var pattern: `SCHEDULE_<SLOT>_CRON`, `SCHEDULE_<SLOT>_ENABLED`. Coprire tutti i 12 slot esistenti + i nuovi.
- **Tier 2 — per-tenant scheduler (v8.0/W4).** SOLO per gli scheduler user-facing che hanno senso per-tenant: `notifications:digest-weekly`, `kb:health-recompute`, `collections:reevaluate`. Tabella `tenant_scheduler_overrides(tenant_id, slot_name, cron, enabled, timezone)`. Cron resolver in `App\Support\Scheduling\TenantSchedulerResolver`. Gli scheduler di **sistema** (`kb:prune-deleted`, `chat-log:prune`, `embedding-cache:prune`, `queue:prune-failed`, `admin-audit:prune`, `admin-nonces:prune`, `kb:prune-orphan-files`) restano **solo Tier 1** (per-host) — non ha senso lasciare a un tenant decidere quando pulisce la sua chat-log retention, è una decisione operativa del SaaS host.

### A3. Notifiche DB-persistite + bell + per-tipo + per-canale
**Decisione.** La sezione D originale è **rifondata e ampliata**. Default-state:
- **DB persistence: default ON** (tabella `notification_events`, retention 90 giorni, `notifications:prune` wirato come 13° slot scheduler).
- **In-app bell + lista "Ultime notifiche": default ON** (top-bar `<NotificationBell/>` + pannello `/admin/notifications` con tabs Unread / Read / Dismissed / All).
- **Canali per-utente per-event-type:**
  - Tabella `notification_preferences(user_id, event_type, channel, enabled BOOL)` — riga per ogni combinazione user × event × channel.
  - UI `/account/notifications`: griglia event_type (righe) × channel (colonne `in_app|email|discord|slack|teams|webhook`). Toggle individuale per cella + "enable all in column" / "enable all in row" bulk.
  - Default-policy globale in `config/askmydocs.php` (es. `kb_doc_created: in_app=on email=off slack=off discord=off`) editabile dal tenant-admin in `/admin/notifications/defaults`.
- **Pruning: default ON** (90gg) con env override `NOTIFICATION_RETENTION_DAYS=0` per disabilitare.

### A4. Toggle "Mostra citazioni controfattuali" default ON sticky
**ACK.** Default ON. Sticky in `user_preferences(user_id, key, value)` letta lato FE all'init di `ChatView`. Override per messaggio possibile (icona occhio nel bubble) ma non persistito — solo override ephemeral.

### A5. Cos'è #9 MCP-as-KB-Debugger (spiegazione)

**Metafora:** AskMyDocs diventa un **"linter live" per la documentazione dei tuoi clienti**.

**Scenario concreto.** Cliente Acme installa AskMyDocs interno + ha il proprio repo `acme/internal-docs` con cartella `docs/` markdown canonical (frontmatter, slug, supersedes, etc.). Sviluppatore Acme apre Claude Code sul suo laptop, lavora sul repo `acme/internal-docs`.

**Senza #9 (oggi):** lo sviluppatore non sa quali docs sono "marciti", quali wikilink sono dangling, quali decisioni andrebbero unite o deprecate. Per scoprirlo deve aprire la web SPA di AskMyDocs, navigare a `/admin/kb/health`, leggere, tornare al suo IDE, modificare a mano, fare PR.

**Con #9:**
1. Sviluppatore Acme aggiunge `askmydocs` come MCP server nel suo `.mcp.json` del Claude Code locale, autenticandosi con un token tenant-scoped emesso da `/admin/mcp/tokens`.
2. Claude Code carica 4 nuovi tool MCP — **tutti read-only o propose-only, MAI server-side write su AskMyDocs**:
   - `list_dangling_wikilinks(project_key)` — torna lista slug wikilinkati ma non esistenti
   - `detect_decision_debt(project_key, min_score)` — riusa #2 health service
   - `suggest_supersession_chain(slug)` — propone catena (es. `dec-cache-v1 → dec-cache-v2 → dec-cache-v3`)
   - `propose_canonical_edit(doc_id, suggested_md)` — torna draft markdown completo da scrivere nel repo
3. Sviluppatore Acme prompta: "Claude, dimmi quali decisioni nel mio docs/ sono da rivedere e fammi una PR".
4. Claude Code chiama `detect_decision_debt(min_score=70)`, riceve 5 slug. Per ognuno chiama `propose_canonical_edit` e ottiene la nuova versione del markdown.
5. Claude Code scrive le 5 modifiche in `acme/internal-docs/docs/` localmente, apre branch + PR su GitHub Acme.
6. Acme review + merge → la GH Action ingest re-importa in AskMyDocs → loop chiuso, **0 scritture dirette su AskMyDocs da parte di consumer external**.

**Why it's killer:**
- Vendi AskMyDocs e regali un **manutentore automatico della KB del cliente**.
- Promotion pipeline (ADR 0003: solo umani via git push + GH action committano canonical) non viene mai bypassata.
- Audit completo: ogni proposta scrive `kb_canonical_audit(event_type=proposed_via_mcp, actor=mcp_token_id, before_json=null, after_json=suggested_md)`.

**Differenza vs `askmydocs-mcp-pack` esistente:** il pack v1.x è il **server admin** (gestione audit/canonical/health da SPA admin). #9 è un set di **tool RBAC-scoped per consumer external** che propongono modifiche da committare nel **loro** Git, non nel KB di AskMyDocs.

### A6. #8 Compliance Differential Pack v1 — feature concrete

**Solo v1 (v2 parked):**
| Componente | Cosa fa |
|---|---|
| **KB Delta report** | Per il trimestre selezionato: list doc `added` (created_at in window), `removed` (deleted_at in window con forceDelete), `superseded` (canonical_status flipped a `superseded` in window), `promoted` (`is_canonical` flipped false→true in window). Per canonical doc che hanno avuto edit nel trimestre: diff snippet markdown (linee aggiunte/rimosse, max 50 linee per doc) |
| **Audit trail aggregato** | Dump filtrato di `kb_canonical_audit` + `admin_command_audits` + `admin_command_nonces.consumed_at` nel trimestre. Gruppi per `event_type` con conteggi e top 20 actor |
| **Tamper-evident hash** | SHA-256 di `(delta_payload_json + audit_payload_json + tenant_id + period_start + period_end)` HMAC-firmato con `config('askmydocs.compliance.hmac_secret')` (per-tenant in vault). Hash nel header report + endpoint `POST /api/admin/compliance/reports/{id}/verify` che ri-calcola |
| **Export PDF + JSON** | PDF tramite Browsershot (riusa `app/Services/Admin/Pdf/`) con cover page + indice + sezioni + hash footer. JSON machine-readable per integrazioni downstream |
| **Trigger** | (a) manuale da `/admin/compliance/reports` button "Generate Q1 2026 report"; (b) cron `compliance:digest-quarterly` (1° gennaio/aprile/luglio/ottobre alle 06:00, configurabile via Tier 2 scheduler per-tenant), default OFF |

**Non incluso in v1 (sarà v8.x o v9.0 — v2):**
- Answer drift replay (richiede #1 Semantic Time Travel)
- Cosine similarity confronto pre/post trimestre
- Bias monitor longitudinale (vive già in `padosoft/laravel-ai-act-compliance` v6.0)

### A7. v2 → roadmap futura
**ACK.** #1 Semantic Time Travel + #8 v2 (answer drift) parked per **v8.x o v9.0**. Aggiungo riga roadmap README ma niente codice in v8.0.

### A8. Admin panel = host AskMyDocs integrato
**CONFERMATO.** Tutti i nuovi screen citati in questo plan vivono sotto il mount SPA host `/app/{any?}` di `app/Http/Controllers/SpaController.php` (routes/web.php). Path completi: `/app/admin/notifications`, `/app/admin/notifications/defaults`, `/app/account/notifications`, `/app/admin/kb/health`, `/app/admin/kb/health/settings`, `/app/admin/collections`, `/app/admin/collections/{id}`, `/app/admin/mcp/tokens`, `/app/admin/compliance/reports`. Componenti React sotto `frontend/src/features/admin/`. **NESSUNA** modifica ai sister `padosoft/*-admin` packages. (I sister `-admin` cross-mounted hanno il loro scope: `pii-redactor-admin`, `eval-harness-ui`, `flow-admin`, `mcp-pack-admin`, `laravel-ai-act-compliance-admin` — non si toccano in v8.0.)

### A9. Cosa va in `askmydocs-pro` (recap completo)
Vedi §E in fondo al plan. Sintesi: tutto quello in v8.0 OSS è **gratuito**; il Pro aggiunge cap-removal + vertical agents + SSO + analytics + connector enterprise. Recap dettagliato sotto.

### A10. #1 + #8 v2 ultimi
**ACK.** Non in v8.0. Riga "Coming in v8.x or v9.0" nel README roadmap.

---

## §B — Roadmap v8.0 (8 settimane W1→W8)

Ordine ottimizzato per **fondazione first → dipendenti dopo**, allineato a R37 (`feature/v8.0` integration branch, sub-branch per Wn, merge to main una volta a fine ciclo) e R39 (rc-tag per Wn).

| Wn | Feature | Effort | Dipende da | Acceptance gate macro |
|---|---|---|---|---|
| **W1** | **D-foundation** Notification System core (schema + dispatcher + in_app channel + email channel + bell + `/admin/notifications` panel + `notifications:prune`) | M | nessuna | ≥1 evento end-to-end dispatched + visualizzato in bell + email mandata in fake + ≥1 Playwright happy + 1 failure path |
| **W2** | **D-channels** Discord + Slack + Teams + custom webhook + per-user preferences UI + per-tenant defaults UI + scheduler Tier 1 (env-configurable cron) | M | W1 | tutti i 4 canali esterni testati con fake HTTP; toggle UI per-cell + bulk; cron env override testato |
| **W3** | **#4 Why-not-cited** + **#3 Counterfactual citations** (in parallel — entrambi additivi, S effort) | S+S | nessuna | retrieval runner-up surfaced in `messages.metadata`; counterfactual neighbor query rispetta `project_memberships` (test arch dedicato); toggle default ON sticky |
| **W4** | **#2 Decision-Debt Heatmap** (schema + `KbHealthService` + formula con pesi configurabili + `kb:health-recompute` cron + `/admin/kb/health` SPA + `/admin/kb/health/settings` weights UI + integrazione D per `kb_decision_debt_threshold` event) + **scheduler Tier 2** (per-tenant overrides per gli scheduler user-facing) | M | D | health_score calcolato per fixture seed; weight UI preview funziona; digest weekly dispatched dopo recompute; ≥1 Playwright drill-down |
| **W5** | **E-foundation** Living Collections (schema + CRUD API + `/admin/collections` SPA list+create+edit + static-criteria evaluator + manual-add/remove + threshold preview UI) | M | nessuna | crea collection con criteria statici → 1 ingest dispatch → membership auto-popolata + manual override testato |
| **W6** | **E-semantic** Living Collections semantic + retro-eval + chat scoping + MCP resource exposure + integrazione D (`collection_new_member` event) | M | W5, D | semantic prompt embedding → cosine ≥ threshold → membership; retro-eval su 100-doc fixture < 60s; chat picker funziona; resource MCP listabile da pack |
| **W7** | **#9 MCP-as-KB-Debugger** (tenant tokens model + admin SPA mint/revoke + 4 propose-only tool + consumer playbook + test demo integration + audit hook) | M | mcp-pack v1.4 ✅ già lì | token RBAC scope test architettura; 4 tool invocati da MCP inspector → output corretto + audit row scritto; playbook README testato manualmente |
| **W8** | **#8 v1 Compliance Differential Pack** (delta report + audit aggregate + tamper-evident hash + PDF/JSON export + `compliance:digest-quarterly` cron + `/admin/compliance/reports` SPA) + **RC + GA close** | S+close | tutte le precedenti | report Q1 fixture generato + PDF apre + JSON validato + verify endpoint conferma hash; v8.0.0 GA tag pinned |

**Acceptance gate trasversali (ogni Wn):**
- CI verde (PHPUnit + Vitest + Playwright + architecture tests + verify-e2e-real-data.sh)
- R10 canonical-awareness rispettato (ogni nuova query su `knowledge_documents` usa scope corretti)
- R30 tenant isolation rispettato (test architettura dedicato per ogni nuova tabella tenant-aware)
- R36 Copilot review loop chiuso (0 must-fix outstanding + tutte CI green)
- R39 rc-tag pinnato a SHA closure-commit + README features + changelog aggiornati

---

## §C — ADR sketch + task atomiche per feature

### §C.1 — ADR 0012 + W1 Notification System core (D-foundation)

**ADR 0012 — Database-backed multi-channel notification system.**
- **Status:** proposed
- **Context:** host AskMyDocs non ha nessuna infrastruttura notifiche; eventi di interesse (kb canonical promoted, doc created/modified, decision debt threshold, weekly digest, collection_new_member) vanno dispatched a 6 canali (in_app, email, discord, slack, teams, webhook) con preferenze per-user-per-event-per-channel.
- **Decision:** tabella `notification_events` (storage + bell feed), `notification_preferences` (matrix), `notification_digests` (aggregati settimanali); dispatcher Laravel event-listener; canali implementati come `NotificationChannelInterface` con 1 adapter per canale; bell SPA con polling 30s (no WebSocket in v8.0 — defer Reverb a v8.x se serve).
- **Consequences:** schema nuovo per 3 tabelle; tutti gli event publisher esistenti vanno wirati a `KbDocumentChanged` / `KbCanonicalPromoted` etc. via Listener; default-policy in `config/askmydocs.php` editabile da tenant-admin; `notifications:prune` aggiunto come 13° slot scheduler default 90gg.

**Task W1.1 — Schema + Models + Migrations** (✅ shipped commit `aee622d` su `feature/v8.0-W1.1-notif-schema` — PR #188)
- **Obiettivo:** creare tabelle + Eloquent model.
- **Cosa fa:** tre nuove migration con `tenant_id` mandatory (R31) + `timestamps()` su tutte:
  - `notification_events`: `id`, `tenant_id`, `user_id` nullable + FK cascade su `users`, `event_type`, `payload` JSON, `channel_dispatch_log` JSON nullable, `read_at?`, `dismissed_at?`, `timestamps()`. Composite index `(tenant_id, user_id, dismissed_at, read_at, created_at)` per la bell-hot-path + `(tenant_id, event_type)` per admin filter + `(tenant_id, created_at)` per retention sweep.
  - `notification_preferences`: `id`, `tenant_id`, `user_id` NOT NULL + FK cascade, `event_type`, `channel`, `enabled` BOOL default true, `timestamps()`. `UNIQUE(tenant_id, user_id, event_type, channel)` (idempotent upsert) + index `(tenant_id, event_type, channel, enabled)` per il dispatcher lookup di W2.
  - `notification_digests`: `id`, `tenant_id`, `week_start_date` date, `payload` JSON, `sent_at?`, `recipients_count` unsigned int default 0, `timestamps()`. `UNIQUE(tenant_id, week_start_date)`. **Nessun `user_id`** — digest è per-tenant, fan-out ai recipient avviene al render dell'email in W2.
  - I 3 model usano `BelongsToTenant` trait. Composite uniques iniziano sempre da `tenant_id` (R30).
- **Acceptance gate:**
  - `php artisan migrate:fresh` verde (no seeder dedicato in W1.1)
  - Architecture test `tests/Architecture/TenantIdMandatoryTest` enumera 3 nuovi model
  - PHPUnit feature test crea evento, legge, mark-read, dismiss + cross-tenant coexistence + composite-unique enforcement

**Task W1.2 — Event publisher wiring**
- **Obiettivo:** ogni mutazione interessante emette un Laravel event.
- **Cosa fa:** events `KbDocumentChanged`, `KbCanonicalPromoted`, `KbDecisionDebtThreshold` (placeholder per W4), `CollectionNewMember` (placeholder per W6). Listener `NotificationDispatcher` ascolta tutti, applica `notification_preferences` filter, dispatcha `NotifyUserJob` per ogni (user × channel) abilitato.
- **Acceptance gate:** feature test ingerisce 1 doc → `KbDocumentChanged` fired → listener scrive 1 row in `notification_events` per ogni user iscritto a `kb_doc_created`

**Task W1.3 — `NotificationChannelInterface` + `InAppChannel` + `EmailChannel`**
- **Obiettivo:** astrazione per canali + 2 implementazioni baseline.
- **Cosa fa:** interface in `app/Notifications/Channels/`. `InAppChannel::send($event, $user)` scrive in `notification_events`. `EmailChannel::send` usa `Mail::to($user)->queue(new NotificationMail($event))` con template MJML in `resources/views/emails/notification.blade.php` + unsubscribe link HMAC-signed per (tenant, user, event_type).
- **Acceptance gate:** feature test fired → `Mail::fake()->assertQueued(NotificationMail::class)` + `notification_events` row visibile

**Task W1.4 — Bell SPA + `/admin/notifications` panel**
- **Obiettivo:** UI integrata nel host admin shell.
- **Cosa fa:** componenti React `frontend/src/features/notifications/NotificationBell.tsx` (top-bar bell + unread badge + dropdown ultime 5) e `frontend/src/features/notifications/NotificationPanel.tsx` (full panel con tabs Unread/Read/Dismissed/All, filter per event_type, bulk mark-read). Polling 30s via TanStack Query (no WebSocket). Testid hierarchy per R29 (`notif-bell`, `notif-panel-tab-{state}`, `notif-row-{id}-mark-read`, etc.).
- **Acceptance gate:**
  - Vitest unit su 2 componenti
  - Playwright happy: login, fire evento via testing endpoint, bell badge va a 1, click bell, dropdown mostra evento, click "mark all read", badge a 0
  - Playwright failure: evento dispatch fallisce → bell mostra `data-state="error"` con retry button

**Task W1.5 — `notifications:prune` cron + retention env**
- **Obiettivo:** retention default 90gg.
- **Cosa fa:** `App\Console\Commands\PruneNotificationsCommand` con `--days=N` (default `config('askmydocs.notifications.retention_days', 90)`). Slot scheduler in `bootstrap/app.php`: `dailyAt('04:10')` (con env override `SCHEDULE_NOTIFICATIONS_PRUNE_CRON` per Tier 1).
- **Acceptance gate:** feature test crea 100 row con `created_at - 100d`, esegue prune, assertion ≤ 0 row residue

---

### §C.2 — ADR 0013 + W2 Notification channels + preferences

**ADR 0013 — External notification channels via composer-discoverable adapters.**
- **Status:** proposed
- **Context:** dopo W1 dobbiamo aggiungere Discord, Slack, Teams, custom webhook + UI preferences + scheduler tunabile per-host.
- **Decision:** 4 nuovi `NotificationChannelInterface` adapter; canali esterni usano HTTP POST con HMAC signature; UI preferences = griglia per-cell con bulk; scheduler Tier 1 (env override per ogni slot).
- **Consequences:** `.env.example` cresce di ~24 nuove righe (`DISCORD_WEBHOOK_URL`, `SLACK_WEBHOOK_URL`, etc. opzionali); test usano `Http::fake()`; nessun SDK esterno (rispetta convenzione "no AI SDKs").

**Task W2.1 — `DiscordChannel` + `SlackChannel` + `TeamsChannel` + `WebhookChannel`**
- **Obiettivo:** 4 adapter pluggabili.
- **Cosa fa:** ogni adapter implementa `send($event, $user)`; rate-limit interno (10 msg/sec per canale via `RateLimiter::for('notif-discord')`); retry 3× con backoff [5,30,120]s; payload formato canonical webhook (Discord embed / Slack blocks / Teams adaptive card / generic webhook con HMAC `X-AskMyDocs-Signature`).
- **Acceptance gate:** unit test per ogni adapter con `Http::fake()` → assertion sul body POST; retry test su 503; HMAC verify test per WebhookChannel

**Task W2.2 — `/account/notifications` preferences grid**
- **Obiettivo:** UI per-user-per-event-per-channel toggle matrix.
- **Cosa fa:** React component `NotificationPreferencesGrid.tsx` con righe = event_type, colonne = channel. Ogni cella = checkbox controlled. Bulk "enable all in row" / "enable all in column". Save via TanStack Query mutation, optimistic update con R25 dedupe pattern.
- **Acceptance gate:**
  - Vitest unit con grid 6 event × 6 channel
  - Playwright happy: utente toggla 3 cell + bulk-enable colonna → reload pagina → state persistito

**Task W2.3 — `/admin/notifications/defaults` tenant defaults**
- **Obiettivo:** tenant-admin imposta default per nuovi utenti.
- **Cosa fa:** controller `AdminNotificationDefaultsController` espone GET/PUT su `config('askmydocs.notifications.defaults')` (tenant-overridden in `tenant_settings`). Quando un nuovo `User` viene creato, hook `User::created` popola `notification_preferences` dai default tenant.
- **Acceptance gate:** feature test cambia default, crea nuovo user, asserta righe pref popolate

**Task W2.4 — Scheduler Tier 1 (env-configurable cron per-host)**
- **Obiettivo:** rendere tunabili i 12+1 slot scheduler via env.
- **Cosa fa:** refactor `bootstrap/app.php`: ogni `Schedule::command(...)` legge cron da config + `enabled` flag. Pattern uniforme `config('askmydocs.schedule.<slot>.cron', '<default>')` + `->when(fn () => config('askmydocs.schedule.<slot>.enabled', true))`. Aggiungi `.env.example` con tutti i 13 `SCHEDULE_<SLOT>_CRON` + `SCHEDULE_<SLOT>_ENABLED` documentati.
- **Acceptance gate:**
  - PHPUnit `bootstrap/app.php` test override cron → cambia output `php artisan schedule:list`
  - Docs match code (R9): README aggiornato con tabella 13 slot + env var

---

### §C.3 — ADR 0014 + W3 Why-not-cited (#4) + Counterfactual (#3)

**ADR 0014 — Additive retrieval transparency (runner-up + counterfactual).**
- **Status:** proposed
- **Context:** utenti chiedono trasparenza ("perché non hai citato X?" e "se filtravo Y avresti citato Z"); entrambi sono additivi al `messages.metadata` (R27 — additive only).
- **Decision:** `KbSearchService` ritorna ora `(primary, runner_up[], expanded[], rejected[], counterfactual[])`; `messages.metadata.retrieval_runner_up` + `messages.metadata.counterfactual` popolate sempre (counterfactual gated su feature flag default ON sticky per-user). Chat UI mostra 2 nuove tab/section + feedback button per W7 (`chunk_retrieval_feedback`).
- **Consequences:** nessun breaking change (R27); 2-4× embedding lookup per query con counterfactual ON, cache `(query_hash, project_key)` TTL 1h copre ~80% (cap costo); RBAC `project_memberships` deve filtrare strict-ly i project neighbor.

**Task W3.1 — `KbSearchService::searchWithContext` runner-up output**
- **Obiettivo:** slice top-k + runner-up con demotion reason.
- **Cosa fa:** modifica `KbSearchService` per ritornare top-k come primary + top-(k+1..k+15) come runner_up con `reason ∈ {below_rerank_threshold, demoted_by_status_penalty, deduplicated_by_doc, outside_context_window}`. Popola `messages.metadata.retrieval_runner_up` in `KbChatController`.
- **Acceptance gate:**
  - Feature test fixture con 30 chunk → top 10 primary + 15 runner_up con reason valorizzato
  - R27 contract test: `meta.latency_ms` ancora int; nuova chiave non breaks parser esistenti

**Task W3.2 — Nuova tabella `chunk_retrieval_feedback`**
- **Obiettivo:** raccolta segnali user su should_have / should_not.
- **Cosa fa:** migration + model + controller `POST /api/messages/{id}/feedback` con body `{chunk_id, signal}`. `tenant_id` mandatory (R31). FK soft (knowledge_chunks può essere hard-deleted, feedback row vive comunque per analytics).
- **Acceptance gate:** feature test 2 user mandano feedback opposti sullo stesso chunk → 2 row distinti

**Task W3.3 — FE "Considered but not used" tab**
- **Obiettivo:** UI nel ChatView per esporre runner-up.
- **Cosa fa:** componente `RetrievalRunnerUpPanel.tsx` accanto a `CitationsPanel.tsx`. Per ogni runner-up mostra titolo doc + score + reason badge + 2 button "Should have cited" / "Was not relevant". Click → POST `/feedback`.
- **Acceptance gate:**
  - Vitest unit con 5 runner-up
  - Playwright happy: chat domanda, click tab "Considered (N)", click "Should have cited" → toast success + button disabled

**Task W3.4 — Counterfactual neighbor query**
- **Obiettivo:** seconda passata async su project neighbor.
- **Cosa fa:** nuovo `CounterfactualService::pick($query, $userId, $tenantId)`: legge `project_memberships` user, prende top-3 progetti diversi dal primary; per ognuno cache lookup `(query_hash, project_key)` → se miss esegue mini-retrieval (top-5 only, no reranker); popola `messages.metadata.counterfactual = [{project_key, top_chunks:[...]}]`. **Critical RBAC test**: utente A iscritto a proj P1+P2; query forza il counterfactual a tentare P3 (a cui A non ha accesso); test asserta che P3 non appare.
- **Acceptance gate:**
  - Architecture test dedicato RBAC counterfactual (NO project_key fuori dai membership)
  - Feature test cache hit secondo call < primo call (latency proxy)

**Task W3.5 — FE counterfactual panel + toggle ON sticky**
- **Obiettivo:** card espandibile sotto risposta + preference user.
- **Cosa fa:** componente `CounterfactualPanel.tsx` collapsed di default ma con badge `📂 N other projects`. Click espande mostrando chunks. Toggle globale in `/account/preferences` (default ON, sticky). Override per-messaggio via icona occhio (ephemeral).
- **Acceptance gate:**
  - Playwright happy: nuovo user → toggle è ON di default → chat → counterfactual panel visibile
  - Playwright sticky: toggle OFF → reload pagina → toggle ancora OFF

---

### §C.4 — ADR 0015 + W4 Decision-Debt Heatmap (#2) + Scheduler Tier 2

**ADR 0015 — Decision-debt as derived health snapshot + configurable weights.**
- **Status:** proposed
- **Context:** canonical docs accumulano "marciume" (no edit, molti `invalidated_by`, utenti chiedono decisioni morte); serve segnale operativo "queste N decisioni vanno toccate questa settimana" tunabile per tenant.
- **Decision:** tabella `kb_canonical_health_snapshot` derivata (zero mutazioni su `knowledge_documents` / `kb_nodes`); formula 5-fattori con pesi config-driven + per-tenant override; cron `kb:health-recompute` con cron tunabile (Tier 1 + Tier 2); admin SPA heatmap + drill-down + weights UI con preview.
- **Consequences:** nuovo scheduler + nuova tabella health snapshot; integrazione D per evento `kb_decision_debt_threshold` come digest settimanale.

**Task W4.1 — Schema `kb_canonical_health_snapshot`**
- **Obiettivo:** persistenza derivata.
- **Cosa fa:** migration `(id, tenant_id, project_key, doc_id, computed_at, age_days, supersedes_inbound_count, invalidated_by_count, repeat_question_hits_30d, status_decay FLOAT, health_score FLOAT, suggested_action ENUM('review','merge','deprecate','keep'), reviewed_at?, mute_until?, created_at, updated_at)` + UNIQUE(tenant_id, doc_id, computed_at).
- **Acceptance gate:** R31 architecture test; PHPUnit factory

**Task W4.2 — `KbHealthService` + formula pesi config-driven**
- **Obiettivo:** calcolo health_score per ogni canonical doc.
- **Cosa fa:** service con metodo `compute($tenantId)`: per ogni canonical doc tenant, calcola 5 fattori normalizzati [0,1] + pesa via `weights($tenantId)` (default da `config`, override da `tenant_settings`). Repeat-questions usa embedding clustering su `chat_logs` ultimi 30gg (riusa `EmbeddingCacheService`). Upsert in `kb_canonical_health_snapshot`. `suggested_action` derivata da soglie config (`{review: 50-69, merge: 70-84, deprecate: 85-100}`).
- **Acceptance gate:**
  - Unit test su formula con fixture sintetica (3 doc: fresco vs vecchio vs zombie)
  - Test pesi configurabili: cambio peso → score cambia coerentemente

**Task W4.3 — Cron `kb:health-recompute` + Tier 2 scheduler**
- **Obiettivo:** schedulazione tunabile per-host + per-tenant.
- **Cosa fa:** comando Artisan + slot scheduler default `dailyAt('03:50')` env-overridable (Tier 1). Tabella `tenant_scheduler_overrides(tenant_id, slot_name, cron, enabled BOOL, timezone)`. Resolver `TenantSchedulerResolver::cron($slot, $tenantId)` letto da scheduler ad ogni tick per gli slot user-facing. Implementazione: per gli slot Tier 2, loop su tenant nel comando stesso (`SELECT DISTINCT tenant_id FROM kb_canonical_health_snapshot`) e per ogni tenant decidere se eseguire ora basandosi su `tenant_scheduler_overrides`.
- **Acceptance gate:**
  - PHPUnit override cron tenant A diverso da tenant B → tenant A esegue, tenant B no
  - Architecture test: solo slot whitelist (`kb:health-recompute`, `notifications:digest-weekly`, `collections:reevaluate`) possono usare Tier 2; system slot NO

**Task W4.4 — API + SPA `/admin/kb/health`**
- **Obiettivo:** heatmap + drill-down.
- **Cosa fa:** `GET /api/admin/kb/health` paginated con filter `project_key`, `min_score`, `older_than_days`, `canonical_type`, `suggested_action`. Componente React `KbHealthHeatmap.tsx` con matrice canonical_type × age bucket (colore = avg health_score) + tabella sotto con drill-down. Action `POST /api/admin/kb/health/{id}/ack` segna `reviewed_at` + `mute_until` (default 60d configurable). Action `POST /api/admin/kb/health/{id}/promote-deprecate` apre draft `canonical-deprecate` (riusa promotion pipeline ADR 0003).
- **Acceptance gate:**
  - Playwright happy: filter "older than 90d" → mostra subset coerente → ack 1 doc → riga sparisce da default view → "show acked" toggle la riporta
  - R10 canonical-awareness: query usa scope `canonical()->accepted()`

**Task W4.5 — Weights UI `/admin/kb/health/settings` + preview**
- **Obiettivo:** sliders pesi con preview dei top-20 risultati prima di salvare.
- **Cosa fa:** componente `KbHealthWeightsForm.tsx` con 5 slider + form. Endpoint `POST /api/admin/kb/health/weights/preview` accetta pesi candidati e ritorna top-20 doc ricalcolati al volo (no persistenza). Save scrive su `tenant_settings`.
- **Acceptance gate:** Playwright happy: cambia 1 slider → preview cambia top-20 → save → recompute → heatmap riflette nuovi pesi

**Task W4.6 — Integrazione D per `kb_decision_debt_threshold` event**
- **Obiettivo:** digest settimanale opt-in DPO.
- **Cosa fa:** dopo ogni `kb:health-recompute`, calcola "doc con score ≥ 80 non reviewed da 30d"; fires `KbDecisionDebtThreshold` event con payload aggregato; listener D dispatcha ai user iscritti (default per role `dpo`/`editor`).
- **Acceptance gate:** feature test fired → notification_events row creata per user `dpo`

---

### §C.5 — ADR 0016 + W5+W6 Living Collections (E)

**ADR 0016 — Dynamic auto-curated document collections.**
- **Status:** proposed
- **Context:** utenti vogliono "playlist agentiche" che auto-includono nuovi doc per criteri statici (tag/project) + semantic prompt.
- **Decision:** 2 tabelle (`kb_collections` + `kb_collection_members`); nome "Living Collections" (vedi A5 originale); evaluator job dispatched on ingest success + retro-eval su criteri change; chat scoping + MCP resource exposure.
- **Consequences:** embedding cost trascurabile con cache; cap 50 collection per tenant (config) — rimosso in Pro; integrazione D per `collection_new_member` event.

**Task W5.1 — Schema `kb_collections` + `kb_collection_members`**
- **Obiettivo:** persistenza collection + membership.
- **Cosa fa:** migration con campi da originale (vedi §E della sessione). UNIQUE(tenant_id, owner_user_id, name) + UNIQUE(collection_id, knowledge_document_id). Soft delete su `kb_collections`. `semantic_prompt_embedding vector(N)` su pgsql, JSON su sqlite test.
- **Acceptance gate:** R31 architecture + factory

**Task W5.2 — CRUD API + `/admin/collections` SPA list+create+edit**
- **Obiettivo:** UI base.
- **Cosa fa:** controller `AdminCollectionsController` con index/show/store/update/destroy; React `CollectionsList.tsx` + `CollectionEditor.tsx` con form (name, description, criteria JSON con field-builder UI, semantic_prompt textarea opzionale, threshold slider opzionale, visibility radio).
- **Acceptance gate:** Playwright CRUD happy + 422 validation

**Task W5.3 — Static evaluator job + `EvaluateCollectionsJob`**
- **Obiettivo:** auto-include su ingest.
- **Cosa fa:** dispatched da `IngestDocumentJob` on success. Per ogni collection del tenant del doc, valuta criteri statici (project_key in $criteria.projects, tags intersect, canonical_type match, slug glob match). Se match → upsert `kb_collection_members(reason=static_match)`.
- **Acceptance gate:** feature test ingest doc → membership auto-popolata per 2 collection con criteri matching

**Task W5.4 — Manual add/remove + `manually_excluded` mute**
- **Obiettivo:** override umano.
- **Cosa fa:** endpoint `POST /api/admin/collections/{id}/members` (manual add) + `DELETE /api/admin/collections/{id}/members/{doc_id}` (segna `manually_excluded=true`, sopravvive retro-eval). UI in `/admin/collections/{id}` detail.
- **Acceptance gate:** Playwright happy add+remove + retro-eval test conferma exclusion persiste

**Task W5.5 — Threshold live preview slider**
- **Obiettivo:** calibrare prima di salvare.
- **Cosa fa:** endpoint `POST /api/admin/collections/preview` accetta `(criteria, semantic_prompt, threshold)` candidate e ritorna "with this config, N docs would be included". UI nel form editor.
- **Acceptance gate:** Playwright slider da 0.5→0.9 → count diminuisce coerentemente

**Task W6.1 — Semantic criteria + embedding + retro-eval**
- **Obiettivo:** prompt natural language → embedding match.
- **Cosa fa:** on save collection con `semantic_prompt` valorizzato, embed via `AiManager::embed()` e salva `semantic_prompt_embedding`. In `EvaluateCollectionsJob`, dopo static check, se collection ha semantic prompt: embed `(title + first chunk abstract)` cached, cosine vs prompt_embedding, se ≥ threshold → upsert (reason=semantic_match, score). Comando `collections:reevaluate {--collection=ID|--all-tenant=ID}` per retro-eval intero corpus tenant (idempotent, rispetta `manually_excluded`).
- **Acceptance gate:**
  - Feature test retro-eval su 100-doc fixture < 60s
  - Unit test threshold edge (esattamente threshold → incluso; threshold-0.001 → escluso)

**Task W6.2 — Chat collection picker**
- **Obiettivo:** scope conversazione a collection.
- **Cosa fa:** ChatView aggiunge dropdown "Scope: All / Collection X / Collection Y"; selezione filtra `KbSearchService` per `knowledge_document_id IN (SELECT FROM kb_collection_members WHERE collection_id = X)`. Sticky per conversation.
- **Acceptance gate:** Playwright happy: seleziona collection con 5 doc → chat domanda → citations ⊆ {5 doc}

**Task W6.3 — MCP resource exposure**
- **Obiettivo:** ogni collection è subscribable da consumer external.
- **Cosa fa:** nuovo MCP resource type `collection://{tenant}/{collection_id}` esposto via `KnowledgeBaseServer`. `list_resources()` torna tutte le collection visibili al token. `read_resource(uri)` torna lista members + score.
- **Acceptance gate:** MCP inspector integration test conferma listing + read

**Task W6.4 — Integrazione D `collection_new_member` event**
- **Obiettivo:** notifica quando new doc entra in collection.
- **Cosa fa:** in `EvaluateCollectionsJob`, dopo upsert nuovo member, fires `CollectionNewMember` event. Listener D filtra per user iscritti alla collection (default: owner; tenant member opt-in).
- **Acceptance gate:** feature test ingest matching doc → 1 notification_events row per owner

---

### §C.6 — ADR 0017 + W7 MCP-as-KB-Debugger (#9)

**ADR 0017 — Propose-only MCP tools for consumer-side KB self-improvement.**
- **Status:** proposed
- **Context:** consumer del SaaS hanno doc canonical in Git repo loro; vogliono Claude Code locale che propone PR di pulizia su base health/dangling/supersession analysis di AskMyDocs.
- **Decision:** nuove abilities Sanctum `mcp:read` + `mcp:propose` (NO `mcp:write`); 4 nuovi tool propose-only su `KnowledgeBaseServer`; tabella `mcp_tenant_tokens` separata da Personal Access Token; ogni proposta scrive `kb_canonical_audit(event_type=proposed_via_mcp)`; consumer helper `php artisan askmydocs:mcp:connect`.
- **Consequences:** RBAC blast radius = test architettura dedicato; circuit breaker mcp-pack v1.3 estende a nuovi tool; playbook README junior-proof per consumer side.

**Task W7.1 — `mcp_tenant_tokens` model + admin SPA mint/revoke**
- **Obiettivo:** token tenant-scoped emessi da admin host.
- **Cosa fa:** migration `(id, tenant_id, name, token_hash, scopes JSON ['read'|'propose'], last_used_at?, expires_at?, revoked_at?)`. Controller `AdminMcpTokensController` con index/store/revoke. SPA `/admin/mcp/tokens` con tabella + button "Mint" (modal con name + scopes + expires) + "Revoke" + token visualizzato 1 volta sola dopo mint.
- **Acceptance gate:** Playwright happy mint + token mostrato + revoke + lista aggiornata

**Task W7.2 — 4 nuovi MCP tool propose-only**
- **Obiettivo:** tool consumer-side.
- **Cosa fa:**
  - `list_dangling_wikilinks(project_key)` — query `kb_nodes` con `payload_json->dangling=true`
  - `detect_decision_debt(project_key, min_score)` — riusa `KbHealthService` filtrato
  - `suggest_supersession_chain(slug)` — segue `kb_edges` di tipo `supersedes` ricorsivamente
  - `propose_canonical_edit(doc_id, suggested_md)` — valida via `CanonicalParser::parse($suggested_md)`, ritorna `{valid, errors, diff}` SENZA scrivere
- **Acceptance gate:** test architettura: ognuno dei 4 tool non chiama mai `Storage::put`, `CanonicalWriter::write`, `IngestDocumentJob::dispatch`

**Task W7.3 — RBAC scope check per tool**
- **Obiettivo:** ogni tool valida scope token e tenant.
- **Cosa fa:** middleware `EnforceMcpScope` su ogni tool: check token attivo, `revoked_at IS NULL`, `expires_at > now()`, scope match, tenant match. Audit ogni invocation in `kb_canonical_audit(event_type=mcp_tool_invoked, actor=token_id, metadata_json={tool_name, args_hash})`.
- **Acceptance gate:** architecture test enumera tutti i tool; ognuno passa attraverso middleware

**Task W7.4 — Consumer helper `askmydocs:mcp:connect` + playbook**
- **Obiettivo:** zero-friction setup lato consumer.
- **Cosa fa:** Artisan command che produce snippet `.mcp.json` per Claude Code del consumer (input: `--server=https://...`, `--tenant=X`, `--token=Y`); README `docs/mcp-debugger-playbook.md` step-by-step (junior-proof per R[runbook]).
- **Acceptance gate:** test integrazione demo: workspace consumer demo connected, esegue `list_dangling_wikilinks` → output corretto + audit row scritta

---

### §C.7 — ADR 0018 + W8 Compliance Differential Pack v1 (#8 v1) + RC + GA

**ADR 0018 — Quarterly compliance differential reports with tamper-evident hash.**
- **Status:** proposed
- **Context:** auditor AI Act/SOC2/ISO27001 vogliono evidence-pack trimestrale dei cambi KB + audit aggregato; v1 senza answer-drift replay.
- **Decision:** generatore report on-demand + cron quarterly; PDF + JSON output; SHA-256 HMAC-firmato per tamper detection; v2 (answer drift) parked perché richiede #1 Semantic Time Travel.
- **Consequences:** nuova tabella `compliance_reports` per persistenza; integrazione `kb_canonical_audit` + `admin_command_audits`; cron Tier 2 (per-tenant opt-in).

**Task W8.1 — Schema `compliance_reports`**
- **Obiettivo:** persistenza report generati.
- **Cosa fa:** migration `(id, tenant_id, period_start, period_end, payload_json, hash_sha256, hash_hmac, pdf_path?, generated_at, generated_by, UNIQUE(tenant_id, period_start, period_end))`.
- **Acceptance gate:** R31 + factory

**Task W8.2 — `ComplianceReportGenerator` service**
- **Obiettivo:** genera delta + audit + hash.
- **Cosa fa:** metodo `generate($tenantId, $periodStart, $periodEnd)`:
  - Section A — KB delta: query `knowledge_documents` filtered by `created_at`/`deleted_at`/`canonical_status` change in window. Per ogni doc modificato canonical, calcola diff snippet (max 50 linee) via `App\Support\MarkdownDiff::compute()`.
  - Section B — Audit aggregate: query `kb_canonical_audit` + `admin_command_audits` filtered, aggregati per event_type con count + top 20 actor.
  - Section C — Hash: `hash_hmac('sha256', json_encode([$delta, $audit, $tenantId, $periodStart, $periodEnd]), config('askmydocs.compliance.hmac_secret'))`.
  - Persist row.
- **Acceptance gate:**
  - Feature test fixture con 10 doc cambi + 30 audit → report contiene tutti + hash stabile su input stabile
  - Tamper test: cambia 1 char nel payload_json → re-hash diverso

**Task W8.3 — PDF + JSON export**
- **Obiettivo:** 2 formati output.
- **Cosa fa:** PDF via Browsershot riusando `app/Services/Admin/Pdf/`, template Blade `resources/views/admin/compliance/report.blade.php` con cover + indice + sezioni + footer hash. JSON download diretto da payload_json.
- **Acceptance gate:**
  - R14 (surface failures loudly): Browsershot fail → 500 + payload errore, NO PDF 0-byte 200
  - Playwright happy: genera report → click "Download PDF" → file ≥1KB

**Task W8.4 — `/admin/compliance/reports` SPA + verify endpoint**
- **Obiettivo:** UI list + generate + verify.
- **Cosa fa:** SPA mostra lista report storici per tenant; button "Generate Q? YYYY"; verify endpoint `POST /api/admin/compliance/reports/{id}/verify` ricalcola hash e torna `{valid: bool, expected_hash, actual_hash}`.
- **Acceptance gate:** Playwright happy generate Q1 2026 → row appare → verify → green badge

**Task W8.5 — Cron `compliance:digest-quarterly` (Tier 2 opt-in)**
- **Obiettivo:** auto-generate fine trimestre.
- **Cosa fa:** Artisan command esegue per tenant con `tenant_settings.compliance_quarterly_auto = true`; default OFF; cron 1° gennaio/aprile/luglio/ottobre 06:00 (Tier 1 env override).
- **Acceptance gate:** feature test 2 tenant (1 opt-in, 1 opt-out) → solo 1 report generato

**Task W8.6 — RC1 tag + README features update + CHANGELOG**
- **Obiettivo:** rispetta R39 + R37.
- **Cosa fa:** docs PR refresh `### Key Features` + `## Changelog` con v8.0.0-rc1 entry + bullet list di tutte le feature W1-W8. Tag `v8.0.0-rc1` su SHA closure-commit di W8.5.
- **Acceptance gate:** GH Release prerelease pubblicata

**Task W8.7 — GA merge feature/v8.0 → main + tag v8.0.0**
- **Obiettivo:** rispetta R37 (once-per-major).
- **Cosa fa:** PR feature/v8.0 → main con `--merge` per preservare W1-W8 integration history; tag `v8.0.0` su merge commit; aggiorna roadmap README flip "v8.0 ⏳ planned" → "v8.0 ✅ shipped YYYY-MM-DD" + deliverables summary (rispetta R[readme_roadmap_status_flip_on_ga]); GH Release stabile.
- **Acceptance gate:** main HEAD = v8.0 closure; tag pinned; CHANGELOG aggiornato; "Coming in v8.x or v9.0" rows aggiunte per #1 + #8 v2

---

## §D — Acceptance gate trasversali (per ogni Wn)

Da rispettare in OGNI sub-PR del ciclo, in aggiunta agli acceptance gate task-specifici:

1. **CI verde:** PHPUnit + Vitest + Playwright + Architecture tests + `scripts/verify-e2e-real-data.sh`
2. **R10 canonical-awareness:** ogni nuova query su `knowledge_documents` usa scope esistenti (`canonical()`, `accepted()`, `byType()`, etc.)
3. **R30 tenant isolation:** test architettura dedicato per ogni nuova tabella tenant-aware
4. **R31 tenant_id mandatory:** test enumerazione modelli aggiornato
5. **R13 E2E real-data:** Playwright NON intercetta route interne (eccezione marker `R13: failure injection`)
6. **R36 Copilot review loop:** `gh pr create --reviewer copilot-pull-request-reviewer`; loop fino a 0 must-fix + tutte CI green
7. **R39 rc-tag per Wn:** dopo merge ultimo PR del Wn, tag `v8.0.0-rcN` su SHA closure-commit con README + CHANGELOG refresh
8. **R9 docs match code:** README/CLAUDE.md/copilot-instructions.md aggiornate se cambiano env var/schema/route/comandi
9. **R14 surface failures loudly:** nessun 200 con body vuoto/null/NaN in error path
10. **R11 + R29 testid hierarchy:** `feature-resource-{id}-{action[-substep]}` su ogni elemento interattivo nuovo

---

## §E — Recap AskMyDocs Pro (privato, a pagamento, separato da v8.0 OSS)

Tutto in v8.0 va in OSS (host AskMyDocs `lopadova/AskMyDocs`). Le seguenti feature **NON entrano in OSS** e vivono nel repo privato `padosoft/askmydocs-pro`:

### Connettori enterprise-specific (vs i connettori reference OSS già in v4.5)
- **OneDrive Azure AD integration** (workspace OAuth con Entra ID, group-based ACL, Azure AD conditional access)
- **Confluence Cloud OAuth** (vs OSS Confluence base — Pro aggiunge space-level ACL, label sync, page-restriction enforcement)
- **Jira Atlassian Connect** (vs OSS Jira base — Pro aggiunge JQL custom + worklog + sprint context)
- **Microsoft Fabric proprietary** (OneLake + Power BI sync, esclusivo enterprise)
- **Salesforce + HubSpot + Dynamics 365** (CRM sync) — pianificati Pro-only
- **Workday + BambooHR** (HRIS sync) — pianificati Pro-only

### Vertical agents (proprietary business logic + training data)
- **E-commerce agent** (Shopify/WooCommerce/Magento adapters + BI prompts + MCP tools custom `padosoft/laravel-ecommerce-mcp`)
- **Patent Box agent** (tax dossier auto-fill, R&D classification, Italian compliance + integrazione `padosoft/laravel-patent-box-tracker` v1.x con golden dataset privato)
- **HR agent** (policy QA + escalation matrix + ATS integration)
- **Legal agent** (contract analysis + clause comparison + redlines + jurisdiction-aware risk scoring)
- **Healthcare agent** (clinical note QA + ICD-10/CPT mapping — solo per clienti HIPAA-BAA firmati)

### Proprietary MCP servers
- **`padosoft/laravel-ecommerce-mcp`** (privato — Shopify/Stripe/Klaviyo tools)
- **`padosoft/laravel-patent-box-mcp`** (privato — Italian tax compliance tools)
- **`padosoft/laravel-legal-mcp`** (privato — contract analysis tools)

### Proprietary training data
- Golden datasets per dominio (legal, healthcare, ecommerce, patent box)
- Refusal-quality manifests vertical-specific
- Few-shot examples curati da esperti settore
- Adversarial test suites enterprise (vs base eval-harness OSS)

### Enterprise SSO/SAML/SCIM
- **Okta** (SAML 2.0 + SCIM provisioning)
- **Auth0** (universal login + rules engine)
- **Microsoft Entra ID** (formerly Azure AD — SAML + OIDC + conditional access)
- **Google Workspace** (SAML + SCIM)
- **OneLogin / JumpCloud** (SAML)
- **Custom SAML/OIDC** adapter (per clienti con IdP proprietario)

### Advanced analytics dashboards
- **Cohort analysis** (retention/engagement per cohort settimanale/mensile)
- **Retrieval quality trends** (precision/recall longitudinale + per-project drill-down)
- **ROI metrics** (cost-per-answer + savings vs human research time)
- **Provider cost breakdown** (per-provider per-tenant fatturazione granulare)
- **Multi-tenant billing console** (con Stripe/Chargebee integration)

### Cap removal (limiti rimossi vs OSS default)
- **Living Collections cap:** OSS = 50 per tenant; Pro = unlimited
- **MCP tenant tokens cap:** OSS = 5 attivi per tenant; Pro = unlimited
- **Compliance reports retention:** OSS = 4 trimestri; Pro = 12 trimestri (3 anni)
- **Notification retention:** OSS = 90 giorni; Pro = configurabile fino a 730 giorni
- **Chat-log retention:** OSS = default 90 giorni; Pro = configurabile fino a 5 anni
- **Embedding cache size:** OSS = LRU 1M righe; Pro = unlimited
- **Concurrent sync connectors:** OSS = max 3 simultanei; Pro = unlimited

### White-label + SLA
- **Rebranding kit** (logo, colori primary, font, dominio custom, email templates rebrand)
- **SLA tier** (4h response Critical, 99.9% uptime, dedicated Slack channel, on-call rotation)
- **Support enterprise tier** (named TAM, quarterly business review, dedicated Solutions Engineer)
- **Professional services** (custom connector dev, custom agent training, on-site workshop)

### Compliance Pack v2 — quando arriva (post-v8.0)
- **Answer drift replay** (richiede #1 Semantic Time Travel)
- **Compliance Bundle Plus** (AI Act + SOC2 + ISO27001 + HIPAA + PCI-DSS evidence templates pre-cooked + auditor on-call quarterly review)
- **Custom audit trail extensions** (per cliente, su richiesta — es. integrazione Splunk/Datadog)

### Resta in OSS v8.0 (per chiarezza — NON Pro)
- Sistema notifiche completo (W1+W2 — tutti i 6 canali + DB persistence + bell + preferences UI + scheduler Tier 1)
- Scheduler Tier 2 per-tenant (W4 — base)
- Decision-Debt Heatmap (#2) + pesi configurabili
- Counterfactual + Why-not-cited (#3 + #4)
- Living Collections base (E) con cap 50
- MCP-as-KB-Debugger (#9) con cap 5 token
- Compliance Differential v1 (#8 v1) con retention 4 trimestri

---

## §F — Verifica end-to-end del ciclo v8.0

A fine ciclo, prima del GA merge:

```bash
# 1. PHPUnit completo
vendor/bin/phpunit
# expected: tutti i feature test W1-W8 verdi (+~150 nuovi test rispetto v8.x baseline)

# 2. Vitest completo
cd frontend && npm test
# expected: tutti gli unit + component test verdi (+~40 nuovi test)

# 3. Playwright completo
cd frontend && npm run e2e
# expected: tutti gli spec verdi (+~15 nuovi spec coprendo le 8 feature)

# 4. Architecture tests
vendor/bin/phpunit tests/Architecture
# expected: nuovi assert su R30/R31 per le ~7 nuove tabelle

# 5. E2E real-data gate
bash scripts/verify-e2e-real-data.sh
# expected: 0 unallowlisted internal route intercept

# 6. Schedule list verifica Tier 1 env override
SCHEDULE_KB_PRUNE_DELETED_CRON="0 4 * * *" php artisan schedule:list | grep kb:prune-deleted
# expected: cron "0 4 * * *" (non default "30 3 * * *")

# 7. MCP tool propose-only smoke test
php artisan askmydocs:mcp:smoke-test --tool=propose_canonical_edit
# expected: validation result; NO scrittura su knowledge_documents (asserted)

# 8. Compliance report generation
php artisan tinker
> $r = app(ComplianceReportGenerator::class)->generate('test-tenant', '2026-01-01', '2026-03-31');
> hash_equals($r->hash_hmac, hash_hmac('sha256', json_encode([$r->payload_json, 'test-tenant', '2026-01-01', '2026-03-31']), config('askmydocs.compliance.hmac_secret')))
# expected: true

# 9. Notification end-to-end
php artisan tinker
> $tenantId = app(\App\Support\TenantContext::class)->current();
> event(new App\Events\KbDocumentChanged(KnowledgeDocument::first()));
> NotificationEvent::forTenant($tenantId)->latest()->first()
# expected: row creata per ogni user iscritto a kb_doc_modified (R30 — tenant-scoped read)

# 10. Decision-debt heatmap end-to-end
php artisan kb:health-recompute --tenant=test-tenant
# expected: kb_canonical_health_snapshot popolata per ogni canonical doc
```

---

## §G — Open items (CHIUSI 2026-05-18)

Tutti risolti in `AskUserQuestion` 2026-05-18 prima dell'apertura branch:

1. ✅ **Cycle label:** **v8.0** (nuovo ciclo dedicato 8w, cut da `main` dopo v7.1.0 GA `2026-05-18 08:54Z` — mcp-pack v1.5 + admin v1.1 wire-up).
2. ✅ **Bell:** **polling 30s** (no Reverb in v8.0). Upgrade WebSocket parked v8.x o v9.0 se serve.
3. ✅ **Discord:** **webhook** (no bot). Canale default `#askmydocs-notifications` configurabile per tenant.
4. ✅ **HMAC compliance:** **statico per-tenant** in `tenant_settings`. Rotation parked Pro/futuro.
5. ✅ **Playbook MCP-debugger:** **solo inglese** (consumer enterprise; clienti IT capiscono inglese tech docs).
6. ✅ **Compliance retention OSS:** **4 trimestri** (12 trimestri Pro).
7. ✅ **Cap-removal lato Pro:** flag `config('askmydocs.tier') === 'pro'` letto dai service che enforcano cap; OSS hard-coded ai default §E.

**`feature/v8.0` aperto 2026-05-18 09:15Z; W1.1 (notification system schema + models) in flight come prima sub-PR.**
