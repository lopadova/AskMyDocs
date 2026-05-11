# PLAN — v4.5 Connector Framework + Vercel AI SDK UI Completion

**Cycle:** v4.5 (post v4.4 GA, pre v5.0 agentic pivot)
**Duration:** ~8 weeks (W1..W8)
**Integration branch:** `feature/v4.5` (R37)
**RC tags expected:** `v4.5.0-rc1` (after W2), `v4.5.0-rc2` (after W5), `v4.5.0-rc3` (after W7), GA `v4.5.0` at W8 closure
**Status:** PLAN — pending kickoff after README refactor PR #147 merges and `feature/v4.5` is cut off main

---

## 1. Cycle goal

> **Position AskMyDocs as enterprise-checkbox-compliant — every Glean / Notion AI / Mendable connector lookup operator expects must be available, AND the chat UI matches Claude/ChatGPT polish.**

Two concurrent value deliveries, one cycle:

1. **Connector framework + 7 reference connectors** — closes Gap 1 + Gap 2 of `AUDIT-2026-05-11-competitor-comparison.md`. Flips the "external sources?" question from "git push only" to "Drive / OneDrive / Notion / Confluence / Jira / Evernote / Fabric out of the box".
2. **Vercel AI SDK v6 UI Tier 1+2+3** — closes the chat-UI parity gap with `vercel/ai-chatbot` per `AUDIT-2026-05-11-vercel-ai-sdk-ui-coverage.md`. Brings AskMyDocs chat from "good RAG UI" to "ChatGPT/Claude-grade polish + generative UI / canvas / artifacts".

The connector framework lays the architectural foundation that v5.0 (MCP client) reuses one cycle later — same plugin pattern, same composer auto-discovery convention, same admin surface skeleton.

---

## 2. Scope — three tracks

### Track A — Connector framework (core architecture)

Single-source-of-truth code lives in `app/Connectors/` and `frontend/src/features/admin/connectors/`. New migrations land under `database/migrations/`. The framework is OS and ships as part of AskMyDocs core.

| Component | Path | Role |
|---|---|---|
| `ConnectorInterface` | `app/Connectors/ConnectorInterface.php` | Contract every connector package implements. Methods: `key()`, `displayName()`, `iconUrl()`, `oauthScopes()`, `initiateOAuth(installationId)`, `handleOAuthCallback(installationId, request)`, `syncFull(installationId)`, `syncIncremental(installationId, since)`, `disconnect(installationId)`, `health(installationId)`. |
| `BaseConnector` | `app/Connectors/BaseConnector.php` | Abstract class with default OAuth state-token handling, token refresh, sync-job dispatch, `kb_canonical_audit` event emission, PII redaction call-out points. Connector packages extend it. |
| `ConnectorRegistry` | `app/Connectors/ConnectorRegistry.php` | Auto-discovery via composer `extra.askmydocs.connectors` array of FQCNs. Validates at boot that each FQCN implements `ConnectorInterface`. Cached per request. Mirrors `PipelineRegistry` pattern (R23). |
| `OAuthCredentialVault` | `app/Connectors/Auth/OAuthCredentialVault.php` | Encrypted-at-rest (Laravel `Crypt`) per-tenant credential store. Reads/writes `connector_credentials` table. Handles refresh-token rotation + expiry check + transparent refresh on stale token. |
| `SyncScheduler` | `app/Connectors/Scheduling/SyncScheduler.php` | Laravel scheduler hooks. Reads each enabled `connector_installations` row + dispatches `ConnectorSyncJob` per row per cron tick (default: every 15 min, configurable per-connector via `config/connectors.php`). |
| `ConnectorSyncJob` | `app/Jobs/ConnectorSyncJob.php` | Queued job. Calls `$connector->syncIncremental($installationId, $lastSyncAt)`. Updates `last_sync_at` + `error_json`. Honours `$tries=3` + `backoff=[60, 300, 900]`. |

**Migrations:**

```php
// database/migrations/2026_05_15_000001_create_connector_installations_table.php
Schema::create('connector_installations', function (Blueprint $table) {
    $table->id();
    $table->string('tenant_id', 50)->default('default')->index();
    $table->string('connector_name', 64);              // e.g. 'google-drive'
    $table->json('config_json')->nullable();           // per-connector knobs (folder filter, label filter)
    $table->enum('status', ['pending', 'active', 'disabled', 'errored'])->default('pending');
    $table->timestamp('last_sync_at')->nullable();
    $table->json('error_json')->nullable();
    $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
    $table->timestamps();
    $table->unique(['tenant_id', 'connector_name'], 'uq_connector_installations_tenant_name');
    $table->index(['tenant_id', 'status']);
});

// database/migrations/2026_05_15_000002_create_connector_credentials_table.php
Schema::create('connector_credentials', function (Blueprint $table) {
    $table->id();
    $table->foreignId('connector_installation_id')
          ->constrained('connector_installations')->cascadeOnDelete();
    $table->text('encrypted_access_token');             // Crypt::encryptString()
    $table->text('encrypted_refresh_token')->nullable();
    $table->timestamp('expires_at')->nullable();
    $table->json('extra_json')->nullable();             // provider-specific (e.g. Notion bot_id)
    $table->timestamps();
});
```

**HTTP entrypoints:**

| Route | Controller method | Spatie Gate |
|---|---|---|
| `GET /api/admin/connectors` | `ConnectorAdminController::index` | `manageConnectors` |
| `GET /api/admin/connectors/{name}/install` | `ConnectorAdminController::startInstall` | `manageConnectors` |
| `GET /api/admin/connectors/{name}/oauth/callback` | `ConnectorAdminController::oauthCallback` | `manageConnectors` |
| `POST /api/admin/connectors/{installationId}/sync-now` | `ConnectorAdminController::syncNow` | `manageConnectors` |
| `POST /api/admin/connectors/{installationId}/disable` | `ConnectorAdminController::disable` | `manageConnectors` |
| `DELETE /api/admin/connectors/{installationId}` | `ConnectorAdminController::destroy` | `manageConnectors` |

**Admin SPA:** `frontend/src/features/admin/connectors/`
- `ConnectorsView.tsx` — list + detail layout
- `ConnectorCard.tsx` — one per installed/installable connector
- `OAuthFlowDialog.tsx` — OAuth start + callback handling
- `ConnectorSyncStatus.tsx` — last sync timestamp + error log
- `ConnectorConfigPanel.tsx` — per-connector config form (folder filter, etc.)

**Gates:**
- `manageConnectors` (Spatie permission) — super-admin only by default
- `viewConnectorAudit` — admin role + super-admin

**Architecture test:**
- `tests/Architecture/ConnectorRegistryTest.php` — asserts every composer-installed connector FQCN implements `ConnectorInterface`. Mirrors R23.

### Track B — 7 reference connector packages

Each connector ships as a separate Laravel package (`padosoft/askmydocs-connector-{name}`). Same skeleton:

```
padosoft/askmydocs-connector-{name}/
├── composer.json               # declares extra.askmydocs.connectors
├── README.md                   # WOW pattern per memory feedback_open_source_readme_quality
├── CHANGELOG.md
├── LICENSE                     # MIT for OS, proprietary for Pro
├── src/
│   ├── {Name}Connector.php                # implements ConnectorInterface
│   ├── {Name}ConnectorServiceProvider.php # registers config + routes
│   ├── Auth/{Name}OAuthHandler.php        # OAuth start / callback / refresh
│   ├── Sync/{Name}DocumentFetcher.php     # paginates external API, normalizes to MD
│   └── Sync/{Name}DeltaQuery.php          # incremental sync via provider delta semantics
├── config/{name}-connector.php
├── routes/api.php                         # connector-specific routes if needed
└── tests/
    ├── Feature/{Name}ConnectorTest.php
    └── Live/{Name}ConnectorLiveTest.php   # opt-in (memory feedback_package_live_testsuite_opt_in)
```

**The 7 connectors:**

| Wn | Package | License | OAuth provider | Incremental sync strategy | Notes |
|---|---|---|---|---|---|
| W1 | `padosoft/askmydocs-connector-google-drive` | MIT (OS) | Google OAuth2 | Drive Changes API (`pageToken` cursor) | Reference impl — drives framework refinements. |
| W2 | `padosoft/askmydocs-connector-notion` | MIT (OS) | Notion OAuth2 | `last_edited_time` query + page-tree walk | Second connector validates framework abstractions. |
| W4 | `padosoft/askmydocs-connector-evernote` | MIT (OS) | Evernote OAuth1 (legacy) + `.enex` file upload | OAuth: `getSyncChunk` API + USN cursor; `.enex`: one-shot import | **Dual mode** in same package (memory directive). Lorenzo's personal use case. |
| W4 | `padosoft/askmydocs-connector-fabric` | MIT (OS) | fabric.so OAuth2 | Workspace + spaces walk + page-modified-since | Second-brain collaborative source. |
| W5 | `padosoft/askmydocs-connector-onedrive` | Proprietary (Pro) | Microsoft Identity Platform OAuth2 | Microsoft Graph `delta` query (proven pattern) | Azure AD enterprise integration; admin-consent flow required. |
| W5 | `padosoft/askmydocs-connector-confluence` | Proprietary (Pro) | Atlassian OAuth2 (3LO) | `CQL last-modified > timestamp` query per space | Confluence Cloud REST API. Per-space scope. |
| W6 | `padosoft/askmydocs-connector-jira` | Proprietary (Pro) | Atlassian OAuth2 (3LO) | JQL `updated >= timestamp` per project | Reuses Confluence auth handler. Issue body + comments → KB doc. |

Each connector package READMEs follow `memory:feedback_open_source_readme_quality` (14 sections: badges, theory, comparison tables vs alternatives, examples, architecture diagram).

### Track C — Vercel AI SDK v6 UI completion

Source: `AUDIT-2026-05-11-vercel-ai-sdk-ui-coverage.md` §3 (Tier 1 + Tier 2 + Tier 3).

**Tier 1 — high-leverage adoption (W6):**

1. Wire `regenerate` button (dead-wired today at `MessageActions.tsx:40-50`). Thread `chat.regenerate` through `MessageBubble.tsx`.
2. Wire `branch` button (same dead-wiring as regenerate). Add BE conversation-fork route + service.
3. `experimental_throttle: 50` on `useChat` — one-line in `use-chat-stream.ts`.
4. Inline message edit — `setMessages` enabler. User edits prior prompt → drop subsequent assistant turn → resubmit.
5. Dynamic suggested follow-up actions — replace static three-prompt `EmptyThread` with BE-driven `data-suggestions` part emitted at stream end.

**Tier 2 — polish (W7):**

1. `source-document` rendering — extend `message-shape-adapters.ts` to accept both `source-url` AND `source-document`.
2. Multimodal input (file/image attachment) — Composer `<input type="file">` + `parts[].type === 'file'` rendering + BE upload + multipart contract.
3. Toast surface — replace inline `chat-composer-error` banner with non-blocking toast (`vercel/ai-chatbot/toast.tsx` pattern).
4. Shimmer placeholder during `status === 'submitted'`.
5. Slash-command palette — `/clear`, `/regenerate`, `/canonical`, `/help`, `/model`.
6. Model picker in chat header — replace static `headerMeta` with `<select>` bound to BE `/api/admin/ai/models`.
7. Generic `data-*` registry — factor `readDataPartField` into typed dispatcher.
8. `onData` consumption for transient events (progress, retrieval-debug) — dev-flag only.

**Tier 3 — generative UI / canvas / artifacts (W7 stretch):**

1. Artifact panel — right-side rich-editor surface (code / docs / sheets / images). Mirrors `vercel/ai-chatbot/components/chat/artifact*`. Cross-feature with Track A (Drive/Notion connectors create previewable docs that surface as artifacts).
2. `useObject` for promotion-candidate validation drafting — partial JSON streams of canonical schema.
3. `resumeStream` + persistent stream IDs — survive tab reload mid-stream. BE first (persistent stream ID + `/conversations/{id}/messages/stream/resume` endpoint), then FE wiring.

W7 is **the heaviest sub-cycle** of the entire v4.5 cycle. If schedule pressure mounts, Tier 3 items 2-3 may slip to v4.6 — Tier 3 item 1 (artifact panel) is the marquee UX deliverable and stays in scope.

---

## 3. W1..W8 breakdown

### W1 — Connector framework core + Google Drive reference

**Sub-PRs:**
- `W1.A` — `app/Connectors/` skeleton (Interface + BaseConnector + Registry + OAuthCredentialVault + SyncScheduler + ConnectorSyncJob)
- `W1.B` — Migrations (`connector_installations` + `connector_credentials`) + Eloquent models + factories
- `W1.C` — Admin HTTP controller + Spatie gate `manageConnectors`
- `W1.D` — `padosoft/askmydocs-connector-google-drive` package (new repo + composer.json + service provider + OAuth handler + DocumentFetcher + DeltaQuery)
- `W1.E` — Architecture test asserting `ConnectorInterface` implementation across registry
- `W1.F` — Wn closure status doc `STATUS-2026-XX-XX-v45-week1-connector-framework.md`

**Files added (high level):**
- `app/Connectors/{ConnectorInterface,BaseConnector,ConnectorRegistry,...}.php`
- `app/Models/{ConnectorInstallation,ConnectorCredential}.php`
- `database/migrations/2026_05_*_create_connector_*_table.php`
- `app/Http/Controllers/Api/Admin/ConnectorAdminController.php`
- `app/Http/Requests/Admin/ConnectorInstallRequest.php`
- `padosoft/askmydocs-connector-google-drive/*` (new repo)
- `tests/Architecture/ConnectorRegistryTest.php`
- `tests/Feature/Connectors/ConnectorFrameworkTest.php`

**Tests expected:** ~40 new tests (framework smoke + GoogleDrive feature + OAuth flow + sync incremental + R23 architecture).

**Dependencies:** none — first Wn of cycle.

**Risk:** **medium** — first Wn always shakes loose interface assumptions; expect 1-2 ConnectorInterface refactors during W2 as Notion stresses different shapes.

### W2 — Notion connector + framework refinements

**Sub-PRs:**
- `W2.A` — `padosoft/askmydocs-connector-notion` package (new repo)
- `W2.B` — Framework refinements based on W1 lessons: ConnectorInterface adjustments, BaseConnector helper extraction, OAuth state-token canonicalisation
- `W2.C` — Admin SPA shell — `ConnectorsView.tsx` + `ConnectorCard.tsx` + `OAuthFlowDialog.tsx` (W3 polishes; this is the scaffold)
- `W2.D` — `rc1` tag — `v4.5.0-rc1` at W2 closure (R39)
- `W2.E` — Wn closure status doc

**Tests expected:** ~35 new tests (Notion feature + framework refinement regression tests).

**Dependencies:** W1 framework + GoogleDrive landed.

**Risk:** **low-medium** — Notion API quirks (pages-vs-databases distinction) need careful normalisation but framework is now battle-tested by GoogleDrive.

### W3 — Connector admin UI polish + OAuth flow hardening

**Sub-PRs:**
- `W3.A` — Admin SPA full functionality — list installed + installable; install flow; per-installation detail; sync-now; disable; destroy
- `W3.B` — OAuth flow hardening — state-token CSRF, expiry, refresh-token rotation, error recovery UI
- `W3.C` — Connector sync scheduling UI (per-connector cron config display + override)
- `W3.D` — Playwright E2E for admin connectors (happy path install + sync + disable + 422 invalid config)
- `W3.E` — Per-connector audit log display in admin (reads `kb_canonical_audit` filtered by `event_type='connector_sync_*'`)
- `W3.F` — Wn closure status doc

**Tests expected:** ~25 new (UI + E2E + audit display).

**Dependencies:** W1 + W2.

**Risk:** **low** — pure FE + glue work over already-stable framework.

### W4 — Evernote (.enex + OAuth) + Fabric

**Sub-PRs:**
- `W4.A` — `padosoft/askmydocs-connector-evernote` package — dual mode (.enex upload + OAuth1)
- `W4.B` — Evernote OAuth1 helper module (Evernote is OAuth1-only, not OAuth2 — distinct from rest of suite)
- `W4.C` — `.enex` parser (XML → markdown) + bulk-import controller
- `W4.D` — `padosoft/askmydocs-connector-fabric` package — fabric.so OAuth2
- `W4.E` — Admin UI: dual-mode display for Evernote (toggle between Bulk Import / OAuth Sync)
- `W4.F` — Wn closure status doc

**Tests expected:** ~30 new.

**Dependencies:** W1+W2+W3 framework + admin UI.

**Risk:** **medium** — Evernote OAuth1 is unlike the rest of the suite; `.enex` parser must handle 10+ years of legacy markup formats (Lorenzo's own data is the test fixture).

### W5 — OneDrive (Pro) + Confluence (Pro)

**Sub-PRs:**
- `W5.A` — `padosoft/askmydocs-connector-onedrive` package (private Pro repo) — MS Graph OAuth2 + delta query
- `W5.B` — Microsoft Identity Platform admin-consent flow handling (multi-tenant Azure AD)
- `W5.C` — `padosoft/askmydocs-connector-confluence` package (private Pro repo) — Atlassian OAuth2 (3LO)
- `W5.D` — Per-space sync config for Confluence (space allowlist UI)
- `W5.E` — `rc2` tag — `v4.5.0-rc2` at W5 closure (R39)
- `W5.F` — Wn closure status doc

**Tests expected:** ~30 new (each Pro connector ships standalone tests; Live tests are opt-in per memory feedback).

**Dependencies:** W1+W2+W3 + admin UI from W3.

**Risk:** **medium** — Azure AD admin-consent flow has nuance (single-tenant vs multi-tenant apps); Confluence rate limits are aggressive.

### W6 — Jira (Pro) + Vercel SDK UI Tier 1

**Sub-PRs:**
- `W6.A` — `padosoft/askmydocs-connector-jira` package (private Pro repo) — reuses Confluence Atlassian OAuth2 handler
- `W6.B` — Jira issue → KB document normalisation (issue body + comments thread → single MD)
- `W6.C` — Vercel SDK UI Tier 1 batch (regenerate / branch / edit / throttle / dynamic suggested actions) — see Track C above
- `W6.D` — BE: `data-suggestions` `StreamChunk` emitter at stream end
- `W6.E` — BE: conversation-fork route + service for `branch` button
- `W6.F` — Wn closure status doc

**Tests expected:** ~40 new (Jira + Tier 1 UI scenarios + R11/R12/R13 Playwright spec).

**Dependencies:** W5 Atlassian OAuth (reused); Track C Tier 1 has no Track A dependency but shares BE work surface with `MessageStreamController`.

**Risk:** **medium** — `branch` requires a conversation-fork BE that doesn't exist today; non-trivial multi-tenant data implication.

### W7 — Vercel SDK UI Tier 2 + Generative UI / Canvas / Artifacts

**Heaviest sub-cycle of the cycle.**

**Sub-PRs:**
- `W7.A` — Tier 2 batch part 1: `source-document` rendering + multimodal input (file/image attachment) + BE upload + multipart contract
- `W7.B` — Tier 2 batch part 2: toast surface + shimmer placeholder + slash-command palette + model picker
- `W7.C` — Tier 2 batch part 3: generic `data-*` registry + `onData` consumption (dev-flag)
- `W7.D` — Tier 3 batch part 1: artifact panel scaffold (right-side rich-editor surface) — code + markdown + diff renderers
- `W7.E` — Tier 3 batch part 2: artifact panel — table + chart + editable form renderers
- `W7.F` — Tier 3 batch part 3: `useObject` for promotion-candidate drafting
- `W7.G` — Tier 3 batch part 4: `resumeStream` + persistent stream IDs (BE first, FE second; multi-PR sub-cycle)
- `W7.H` — `rc3` tag — `v4.5.0-rc3` at W7 closure (R39)
- `W7.I` — Wn closure status doc

**Tests expected:** ~80 new (Tier 2 + Tier 3 combined; largest test pack of the cycle).

**Dependencies:** Tier 1 from W6 (regenerate / branch wired first); BE stream protocol extensions land in same PRs.

**Risk:** **HIGH** — artifact panel is the most complex FE surface AskMyDocs has shipped. `resumeStream` requires persistent server-side stream state (not in `MessageStreamController` today). May slip Tier 3 items 2-3 to v4.6 if W7 looks like overrunning.

### W8 — RC acceptance + GA merge + closure

**Sub-PRs:**
- `W8.A` — RC acceptance test pack — full E2E suite green + Architecture suite green + cohort regression vs v4.4 baseline + adversarial pack green
- `W8.B` — Bug-fix iterations from RC acceptance
- `W8.C` — Documentation refresh — README `Connector` section + per-connector READMEs polished + v4.5 changelog entry
- `W8.D` — Sister-package version locks — every connector package tagged `v1.0.0` (OS) or `v1.0.0` private (Pro) on Packagist
- `W8.E` — `feature/v4.5` → `main` merge per R37 (once-per-major)
- `W8.F` — `v4.5.0` GA tag at merge SHA
- `W8.G` — v4.5 cycle closure doc `STATUS-2026-XX-XX-v45-week8-rc-acceptance.md`

**Tests expected:** ~10 (RC bug fixes only; bulk of testing happened W1-W7).

**Dependencies:** W7 closed cleanly with rc3 green.

**Risk:** **low-medium** — RC iterations occasionally need 2-3 cycles; AI Act compliance touch-points should already be PII-redactor-safe (no extra work).

---

## 4. OS vs Pro matrix

Per `memory:feedback_v45_strategic_roadmap` — framework = OS, vertical-specific enterprise connectors = Pro.

| Component | License | Repo | Reason |
|---|---|---|---|
| Connector framework (`app/Connectors/`) | OS (MIT) | `lopadova/AskMyDocs` core | Foundation — community adoption = moat (pattern: Mattermost / n8n / Cal.com). |
| Connector admin UI | OS (MIT) | `lopadova/AskMyDocs` core | Same. |
| `OAuthCredentialVault` | OS (MIT) | core | Same. |
| `SyncScheduler` + `ConnectorSyncJob` | OS (MIT) | core | Same. |
| `padosoft/askmydocs-connector-google-drive` | OS (MIT) | new public repo | Community reference impl #1. |
| `padosoft/askmydocs-connector-notion` | OS (MIT) | new public repo | Community reference impl #2. |
| `padosoft/askmydocs-connector-evernote` | OS (MIT) | new public repo | Lorenzo personal use; legacy ecosystem coverage. |
| `padosoft/askmydocs-connector-fabric` | OS (MIT) | new public repo | Second-brain ecosystem (fabric.so). |
| `padosoft/askmydocs-connector-onedrive` | **Proprietary (Pro)** | `padosoft/askmydocs-pro` monorepo | Azure AD enterprise integration; SLA + audit-letter attached. |
| `padosoft/askmydocs-connector-confluence` | **Proprietary (Pro)** | `padosoft/askmydocs-pro` monorepo | Atlassian enterprise integration. |
| `padosoft/askmydocs-connector-jira` | **Proprietary (Pro)** | `padosoft/askmydocs-pro` monorepo | Atlassian enterprise integration. |
| Vercel AI SDK Tier 1 | OS (MIT) | core | UX parity is a community deliverable. |
| Vercel AI SDK Tier 2 | OS (MIT) | core | Same. |
| Vercel AI SDK Tier 3 — Artifact panel scaffold | OS (MIT) | core | Framework + 3 reference artifacts in OS. |
| **Vertical artifact renderers** (Patent Box dossier preview, e-commerce dashboard) | **Proprietary (Pro)** | `padosoft/askmydocs-pro` | Vertical-specific business logic = Pro tier. |

---

## 5. Acceptance criteria

Gates for `v4.5.0` GA (W8 RC acceptance pack):

- [ ] All 7 connectors implemented + working OAuth flow + smoke E2E in Playwright
- [ ] Connector framework auto-discovers packages via `composer.json` `extra.askmydocs.connectors` field (architecture test `ConnectorRegistryTest` green)
- [ ] Admin UI lists installed/installable connectors per workspace + install flow + sync-now + disable + destroy
- [ ] Each connector has working incremental sync via provider-specific delta semantics (verified per connector by replay test)
- [ ] Connector audit events land in `kb_canonical_audit` with `event_type LIKE 'connector_*'`
- [ ] PII redactor wraps every connector's incoming content (verified via `BoundaryCoverageTest` extension for connector sync path)
- [ ] Cross-tenant isolation test: `tenant_A` cannot see `tenant_B` connector installations (R30 architecture test extended)
- [ ] Vercel SDK UI Tier 1 items 1-5 all present (regenerate + branch + edit + throttle + dynamic suggested actions)
- [ ] Vercel SDK UI Tier 2 items 1-8 all present (source-document + multimodal + toast + shimmer + slash + model picker + data-* registry + onData)
- [ ] Vercel SDK UI Tier 3: artifact panel scaffold landed with 3+ reference renderers (code + markdown + diff at minimum)
- [ ] `+200 tests` cumulative across the cycle (sum across all Wn closure docs)
- [ ] 3 RC tags: `v4.5.0-rc1` at W2, `v4.5.0-rc2` at W5, `v4.5.0-rc3` at W7
- [ ] GA tag `v4.5.0` at W8 closure (R39 + R37 conventions)
- [ ] All `padosoft/askmydocs-connector-*` OS packages tagged `v1.0.0` on Packagist with full WOW README
- [ ] All Pro connectors tagged `v1.0.0` private in `padosoft/askmydocs-pro` monorepo
- [ ] CI green on `feature/v4.5` HEAD at merge SHA (R36 mandatory Copilot loop + CI green conjunctive)

---

## 6. Risks + mitigations

| Risk | Probability | Impact | Mitigation |
|---|---|---|---|
| W7 generative UI overrun | HIGH | Slips W8 GA | Box Tier 3 items 2-3 (`useObject` + `resumeStream`) as "optional, slip to v4.6 if W7 W7.G doesn't land by Friday W7" |
| OAuth provider API drift | medium | Connector ships broken | Each connector ships Live testsuite (opt-in) that hits real OAuth flow on a sandbox tenant; CI runs Unit only, devs invoke Live before tagging v1.0.0 |
| Composer auto-discovery boot failure | medium | Framework unusable | R23 architecture test gates every PR; boot fails fast on invalid FQCN |
| Tenant credential leak (encryption mistake) | low | RCE-class | `OAuthCredentialVault` MUST use Laravel `Crypt`; encryption-at-rest test + multi-tenant test |
| Vendor lock-in (Pro connectors require Atlassian / Microsoft accounts) | accepted | none | Documented in Pro tier README; OS users use OS connectors |
| Sync-job storms (15-min cron × 100 tenants × 7 connectors = 700 jobs/15min) | medium | DB load | `withoutOverlapping()` + per-connector queue + memory-safe `chunkById(100)` per sync job (R3) |

---

## 7. Branching + release alignment (R37 + R39)

- Cut `feature/v4.5` off `main` after README refactor PR #147 merges
- Every sub-PR `feature/v4.5/W{n}.{letter}` targets `feature/v4.5` (NOT main)
- R39 tags: `v4.5.0-rc1` after W2 closure, `v4.5.0-rc2` after W5, `v4.5.0-rc3` after W7. Each rc captured at closure-commit SHA via `gh release create --target $CLOSURE_SHA --prerelease`
- R37 final merge: `feature/v4.5` → `main` ONCE at W8 closure → tag `v4.5.0` GA at merge SHA
- v3 stability on main preserved per R37 (half-merged v4.5 features never on main)
- `padosoft/askmydocs-connector-*` OS repos follow their own SemVer — each tags `v0.x` rcs during W1-W7 and `v1.0.0` at v4.5 GA

---

## 8. Out of scope (deferred to v4.6 or v5.0)

- **MCP client framework** — v5.0 cycle (paradigm shift)
- **Salesforce / ServiceNow / Slack connectors** — v4.6 candidate (community contribution welcome)
- **SAML / SCIM / OIDC** — separate v4.6 cycle (Gap 3 of competitor audit)
- **Webhook real-time sync** — partially in scope as Track A delta-sync; full webhook framework is v4.6 (extends `padosoft/laravel-flow` with `WebhookInboundStep`)
- **Per-conversation tool authorization** — v5.0 (tied to MCP client)
- **Voice output (TTS)** — v4.7 candidate
- **Public conversation visibility toggle** — out-of-domain for AskMyDocs (KB tool, not chat-share product)

---

## 9. Cross-references

- `docs/v4-platform/AUDIT-2026-05-11-vercel-ai-sdk-ui-coverage.md` — full SDK surface inventory + tier breakdown
- `docs/v4-platform/AUDIT-2026-05-11-competitor-comparison.md` — Gap 1 (connectors) + Gap 5 (generative UI) justification
- `memory:feedback_v45_strategic_roadmap` — Lorenzo decisions log
- `docs/adr/0004-v42-sister-package-integration.md` — sister-package integration pattern (precedent)
- `docs/v4-platform/PLAN-v5.0-agentic-platform-mcp-client.md` — successor cycle plan
- `.claude/skills/branching-strategy-feature-vx/` — R37 branching enforcement
- `.claude/skills/rc-tag-per-week-milestone/` — R39 rc-tag enforcement

---

## 10. Sign-off

This plan was prepared on 2026-05-11 as a planning artefact for the v4.5 cycle. Lorenzo authorised auto-mode kickoff through v4.5 + v5.0 + v6.0 end-to-end (memory `feedback_v45_strategic_roadmap` — Auto-mode roadmap kickoff section). Kickoff sequence after README refactor PR #147 merges:

1. Cut `feature/v4.5` off main
2. This plan + PLAN-v5.0 + PLAN-v6.0 + DESIGN-SPEC-v6.0 land in same prep PR (current PR)
3. Start v4.5 W1 — connector framework core + Google Drive reference

**Status:** PLAN — pending PR #147 merge + branch cut.
