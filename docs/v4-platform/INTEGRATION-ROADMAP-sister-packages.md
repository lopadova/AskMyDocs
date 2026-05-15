# Sister packages integration roadmap

> **Connector framework preamble (v4.5/W1)**
>
> v4.5 introduces a NEW integration shape on top of the sister-package
> pattern: **external-source connectors**. The framework
> (`app/Connectors/*`) lets connectors ship EITHER as built-in classes
> registered in `config/connectors.php::built_in` OR as separate
> Laravel packages declaring their FQCNs under
> `composer.json::extra.askmydocs.connectors`. The `ConnectorRegistry`
> auto-discovers both at boot and validates every FQCN implements
> `App\Connectors\ConnectorInterface` (R23 — fail-loud at boot).
>
> Why this matters for the integration roadmap: future
> `padosoft/askmydocs-connector-*` packages (`google-drive`, `notion`,
> `evernote`, `fabric`, `onedrive`, `confluence`, `jira`) DO NOT
> require a host-app integration sub-PR. The host just installs the
> composer package; auto-discovery handles the rest. This is
> intentionally different from the iframe / cross-mount pattern of
> v4.1-v4.4 sister packages.
>
> See `docs/connectors/README.md` for the developer guide.

---

> **Honest status as of v4.4 RC / GA prep (2026-05-11)**
>
> v4.4 introduces NO new sister-package integrations or version bumps on
> top of v4.3 GA. The cycle is intentionally **scope-tight on host-side
> mount-mode migrations + an operational opt-in**:
>
> - **W1** — `tailwindcss` host bump `^3.4.14` → `^4.0.0` + `@tailwindcss/vite`
>   plugin. Hard prerequisite for W2/W3 per ADR 0005.
> - **W2** — `padosoft/laravel-pii-redactor-admin` v1.0.2 mount mode flipped
>   from iframe → **cross-mount** at `/admin/pii-redactor` (vendored SPA
>   sharing host React 19 + Sanctum + axios). BE unchanged.
> - **W3** — `padosoft/eval-harness-ui` v1.0.0 mount mode flipped from
>   iframe → **cross-mount** at `/admin/eval-harness` (non-prod-only;
>   8-page SPA). 3 fail-closed fences (env flag + APP_ENV + Gate)
>   PRESERVED. NEW host-side `EvalHarnessUiBootstrapController` returns
>   the bootstrap payload (`ui_version` / `metric_labels` / `polling` /
>   `locale` / `tenant_header` / `shortcuts`) derived from
>   `config('eval-harness-ui')`, gated by Spatie role.
> - **W4** — `eval:nightly` adversarial-lane opt-in (2 new env knobs,
>   default OFF). Advisory-only sidecar; baseline-gates-adversarial. ADR 0007.
>
> `padosoft/laravel-flow-admin` stays iframe-mounted forever (Blade + Alpine,
> not React, so cross-mount does not apply per ADR 0005).
>
> v4.3 GA was tagged at `4f375f1` on 2026-05-10. v4.4.0 GA tag fires in W4.B
> once `feature/v4.4` merges into `main` per R37.

---

> **Honest status as of v4.3 RC / GA prep (2026-05-10)**
>
> v4.3 introduces NO new sister-package integrations or version bumps
> on top of v4.2 GA — the cycle is intentionally **scope-tight on host-side
> hardening** (PII boundary coverage extension on top of the existing
> v1.2 surface; React 19 host bump; eval-harness nightly cron consuming
> the existing v1.2 surface). The full integration matrix below is
> unchanged since v4.2 GA on 2026-05-10. The v4.3.0 GA tag itself fires
> in W4.B once `feature/v4.3` merges into `main` per R37.
>
> v4.3 cycle deliverables (host-side, no new sister packages):
>
> - **W1** — `padosoft/laravel-pii-redactor` v1.2 boundary coverage extended
>   from the 4 v4.1 touch-points to **11 persistence-boundary touch-points
>   + 6 admin-readiness inspectors wired**. New observers + listeners +
>   Monolog log channel processor + Flow `CurrentPayloadRedactorProvider`
>   contract binding. 5 new env knobs all default OFF. PR #127.
> - **W2** — React 19 host bump (`react` 18.3.1 → 19.2.6). Dependency-only
>   bump; pre-flight grep confirmed zero breaking patterns. ADR 0005
>   defers Tailwind v3 → v4 + iframe → cross-mount migrations to v4.4.
>   PR #129.
> - **W3** — `padosoft/eval-harness` v1.2 nightly cron consumer: new
>   `eval:nightly` Artisan command + Laravel scheduler entry at 05:30 UTC,
>   default-OFF. Three-fence cost guard. Regression detection +
>   `Log::alert()` + sidecar JSON. ADR 0006. PR #131.
>
> Of the nine `padosoft/*` sister packages currently published on
> GitHub / Packagist, **seven are integrated into AskMyDocs**:
>
> | Package | Where | Since |
> |---|---|---|
> | `padosoft/laravel-ai-regolo` v1.0 | `require` — `app/Ai/Providers/RegoloProvider.php` | v4.0 W2; bumped v0.2 → v1.0 in v4.2/W1 (PR #111) |
> | `padosoft/laravel-pii-redactor` v1.2 | `require` — 4 touch-points (chat / embedding / insights / detokenize) | v4.1 W4.1; bumped v1.1 → v1.2 in v4.2/W1 (PR #112) |
> | `padosoft/laravel-flow` v1.0 | `require` — 9 Flow definitions in `app/Flow/Definitions/` orchestrating every multi-step background pipeline | v4.2/W2 (PRs #114-#117). See ADR 0004 D3. |
> | `padosoft/eval-harness` v1.2 | `require-dev` — RAG regression CI gate (`.github/workflows/rag-regression.yml`) | v4.2/W3 (PR #119). `require-dev` by design — see ADR 0004 D2. |
> | `padosoft/laravel-pii-redactor-admin` v1.0.2 | `require` — mounted under `/admin/pii-redactor` (cross-mount, v4.4/W2) | v4.2/W4 (PR #121); iframe → cross-mount in v4.4/W2 |
> | `padosoft/laravel-flow-admin` v1.0.0 | `require` — mounted under `/admin/flows` (iframe) | v4.2/W4 (PR #122) |
> | `padosoft/eval-harness-ui` v1.0.0 | `require-dev` — mounted under `/admin/eval-harness` non-prod-only (cross-mount, v4.4/W3) | v4.2/W4 (PR #123); iframe → cross-mount in v4.4/W3 |
>
> `padosoft/laravel-patent-box-tracker` and its admin SPA stay
> **EXTERNAL** to AskMyDocs's `composer.json` per ADR 0004 D1 — operators
> install them in their own Laravel project and run
> `php artisan patent-box:cross-repo /path/to/AskMyDocs/tools/patent-box/2026.yml`
> from there. AskMyDocs is the **subject** of the dossier, never the
> tooling host.
>
> All seven integrated packages run through the **R30/R31 cross-tenant
> isolation** disciplines documented in CLAUDE.md, with three different
> tenant-scoping strategies for the three admin SPAs (see ADR 0004 D4):
> supplementary migration + Eloquent observer (pii-redactor-admin);
> Authorizer-level filter (flow-admin); HTTP header injection
> (eval-harness-ui). Every multi-step background pipeline in AskMyDocs
> runs through a Flow definition (see ADR 0004 D3); this is now
> load-bearing for every observable AskMyDocs background workflow.

---

## Per-package integration plan

The order below is driven by **(a) feature urgency for AskMyDocs consumers**
and **(b) maturity of the upstream package implementation**. The package
side is tracked independently in each repo's enterprise plan
(`docs/ENTERPRISE_PLAN.md` for laravel-flow,
`docs/ROADMAP_IMPLEMENTATION_PLAN.md` for eval-harness); AskMyDocs adopts
each package version as it stabilizes rather than waiting for v1.0
across the board.

### `padosoft/laravel-ai-regolo` (W2) — STATUS: ✅ Integrated

| Version | Where in `app/` | What it does |
|---|---|---|
| v0.2.x (current) | `app/Ai/Providers/RegoloProvider.php` | The Regolo provider delegates to `laravel/ai` via the standalone package; chat + streaming + embeddings all flow through the SDK abstraction. No further integration work required for v4.x consumers. |

**No integration milestones pending** — v4.0 GA already ships this fully.

---

### `padosoft/laravel-pii-redactor` (W4.1) — STATUS: ✅ Integrated (v4.1.0 GA)

**Why this lands first** — GDPR exposure on chat persistence is the most
visible production risk among the three pending integrations. AskMyDocs's
`messages` and `chat_logs` tables retain user input verbatim including
free-form text that may contain Italian fiscal identifiers (Codice
Fiscale, Partita IVA), IBAN, credit-card numbers, phone numbers, and
email addresses. The pii-redactor's six checksum-validated detectors
plus the reversible Tokenise strategy let AskMyDocs persist redacted
content for log retention while preserving operator detokenization for
audit trails.

#### v4.1 integration scope

**Sub-task progress (all merged on `feature/v4.1` and rolled into `main` at the v4.1.0 GA tag):**

| Sub-task | Scope | Status |
|---|---|---|
| W4.1.A | `composer require ^1.1` + `config/kb.php` scaffold + `.env.example` | ✅ merged (PR #103, `6a8e978`) |
| W4.1.B | `RedactChatPii` middleware + chat route binding | ✅ merged (PR #104, `8b2abc4`) |
| W4.1.C | `EmbeddingCacheService` pre-redact (mask before hash + provider call) | ✅ merged (PR #105, `06b4c4c`) |
| W4.1.D | `AiInsightsService::coverageGaps()` snippet redact + `LogViewerController::chatDetokenize()` action | ✅ merged (PR #106, `c24ceeb`) |
| W4.1.E | End-to-end architecture test + closure status doc + README/Changelog refresh for v4.1.0-rc1 | ✅ merged (PR #107, `dd93c9e`) |

**Touch points in `app/` (as shipped in v4.1.0 GA):**

1. **`app/Http/Middleware/RedactChatPii.php`** — new middleware
   bound to `POST /conversations/{conversation}/messages` (sync)
   AND `/messages/stream` (SSE) only. When both
   `kb.pii_redactor.enabled` AND
   `kb.pii_redactor.persist_chat_redacted` are true, the middleware
   reads the request `content` field, runs
   `RedactorEngine::redact()` per the host-configured strategy
   (Tokenise / Mask / Hash / Drop in the package config), and
   merges the redacted string back into the request before the
   controller sees it — so what `MessageController` /
   `MessageStreamController` persists into `chat_logs.question` /
   `messages.content` AND what the LLM sees are both the redacted
   form. Architecture-pinned to those two routes only by
   `tests/Architecture/PiiRedactionMiddlewareScopeTest.php` so the
   binding never leaks onto curator / admin / promotion / delete
   routes (would silently corrupt the canonical KB pipeline).

2. **`app/Services/Kb/EmbeddingCacheService::generate()`** — pre-
   embedding redaction step. When
   `kb.pii_redactor.redact_before_embeddings` is true, the service
   runs `RedactorEngine::redact($text, MaskStrategy)` on every
   input string BEFORE the SHA-256 hash that keys the cache row
   AND BEFORE the text reaches the embedding provider's HTTP call.
   Mask strategy (not Tokenise) here because embeddings are one-
   way (no detokenisation round-trip needed) and mask is stable
   (cache hit-rate preserved across re-ingestion).

3. **`app/Services/Admin/AiInsightsService::coverageGaps()`** —
   sample chat snippets surfaced in the daily insights snapshot
   may contain PII. When `kb.pii_redactor.redact_insights_snippets`
   is true, the service applies `RedactorEngine::redact($snippet,
   MaskStrategy)` to every chat sample question BEFORE clustering
   — short-circuiting leakage to BOTH the LLM call AND the snapshot
   persisted into `admin_insights_snapshots.payload_json`. R30
   tenant-scoped on the `chat_logs` read.

4. **`app/Http/Controllers/Api/Admin/LogViewerController::chatDetokenize()`**
   — new endpoint `POST /api/admin/logs/chat/{id}/detokenize`
   gated by 422 (when the active strategy is not `tokenise`) AND
   403 (when the caller lacks the Spatie permission named in
   `kb.pii_redactor.detokenize_permission`, default
   `pii.detokenize`). Every 200 / 403 writes a row to
   `admin_command_audit` (singular table; model
   `App\Models\AdminCommandAudit`) tagged with
   `command = 'pii.detokenize'`. The 422 strategy-mismatch
   preflight is intentionally not audited (config-stage error,
   not an operator action). R30 tenant-scoped on the `chat_logs`
   lookup.

5. **`config/kb.php`** (`pii_redactor` block, all default `false`)
   — five integration-layer knobs:
   - `pii_redactor.enabled` → `KB_PII_REDACTOR_ENABLED` (master switch)
   - `pii_redactor.persist_chat_redacted` → `KB_PII_REDACT_PERSIST` (chat-message middleware gate)
   - `pii_redactor.redact_before_embeddings` → `KB_EMBEDDINGS_PII_REDACT` (`EmbeddingCacheService` pre-redact)
   - `pii_redactor.redact_insights_snippets` → `KB_INSIGHTS_PII_REDACT` (`AiInsightsService` snippet sanitiser)
   - `pii_redactor.detokenize_permission` → `KB_PII_DETOKENIZE_PERMISSION` (default `pii.detokenize` — Spatie permission name)

   The package's own config (detectors, packs, NER drivers, token
   store) is published separately via
   `vendor:publish --tag=pii-redactor-config` using `PII_REDACTOR_*`
   env vars — not mixed into the `KB_*` layer.

   The package's `pii_token_maps` migration auto-loads via
   `PiiRedactorServiceProvider::loadMigrationsFrom()`. Host code
   does NOT publish or mirror it (publishing would conflict with
   the SP load and break `migrate:fresh` under both prod and
   Testbench).

**End-to-end architecture test:**
`tests/Architecture/PiiRedactorIntegrationScopeTest.php` enumerates
all four touch-points + their config-gate names + the R30
tenant-scope markers (`forTenant(` inside `coverageGaps()` and
`chatDetokenize()` method bodies) + the audit-row contract on
`chatDetokenize()` (regex match on
`AdminCommandAudit::query()->create([` AND
`'command' => 'pii.detokenize'`). A regression that drops a gate,
removes a `forTenant()` call, or replaces the config-driven
permission name with a hard-coded literal fails CI at architecture-
test time.

**Tests required:**

- Feature: chat POST with Codice Fiscale in `content` → `messages.content`
  contains `<CF:abc123>`, `pii_token_maps` row exists with the original
  CF, detokenize endpoint returns the original.
- Feature: embedding cache call with PII text → `EmbeddingCacheService`
  invokes `Pii::redact` BEFORE `generateEmbeddings`; assert the text
  passed to the AI manager is the masked variant.
- E2E: admin chat-log viewer renders redacted body by default; "Show
  original" button (gated by permission) reveals the original.
- Architecture: `RedactChatPii` middleware is registered on
  `auth:sanctum` chat routes only (not admin/insights/maintenance).

**Upstream readiness gates:**

- ✅ pii-redactor v1.1.0 shipped (2026-05-03): ItalyPack + GermanyPack +
  SpainPack production-grade detectors, full Tokenise/Mask/Hash strategies,
  DatabaseTokenStore, AuditTrail v2. `composer require` in AskMyDocs landed
  in W4.1.A.

**Estimated v4.1 effort:** 5 sub-tasks (W4.1.A–E). Total ~12-16 R36 cycles of work.

---

### `padosoft/laravel-flow` (W5) — STATUS: 🔴 Scaffold available (v0.1.0 tag, pending Packagist); v4.1 integration target

**Why this lands second** — AskMyDocs's ingestion, deletion, and
promotion pipelines are multi-step flows where partial failures
currently leak. `IngestDocumentJob` retries the whole chain on failure
(`$tries = 3` with backoff `[10, 30, 60]`); a real saga with
reverse-order compensation gives proper rollback semantics for partial
writes (file-on-disk + DB rows + graph nodes + embedding cache entries).

#### v4.1 integration scope (uses laravel-flow v0.2 features)

**Touch points in `app/`:**

1. **`app/Jobs/IngestDocumentJob.php`** — replace the implicit retry
   loop with a Flow definition:
   ```php
   Flow::define('kb.ingest')
       ->step('write_file_to_disk')->compensateWith('delete_file')
       ->step('insert_document_row')->compensateWith('soft_delete_document')
       ->step('chunk_and_insert_chunks')->compensateWith('delete_chunks')
       ->step('generate_embeddings')->compensateWith('rollback_embedding_cache_entries')
       ->step('rebuild_canonical_graph_for_doc')->compensateWith('purge_graph_nodes_for_doc')
       ->register();
   ```
   Failure at any step triggers reverse-order compensation. The
   `flow_runs` + `flow_steps` + `flow_audit` tables (laravel-flow v0.2)
   give per-step audit trail without building it in AskMyDocs.

2. **`app/Services/Kb/Canonical/CanonicalWriter`** — promotion pipeline
   becomes a Flow definition with compensation that reverts the canonical
   markdown if `IngestDocumentJob` fails downstream.

3. **`app/Services/Kb/DocumentDeleter::forceDelete()`** — hard delete is
   currently a sequence (kb_nodes cascade → soft → wait retention →
   hard → file remove). A Flow definition makes the partial-failure
   recovery explicit (e.g., DB hard delete succeeds but file removal
   fails — compensation stages a retry queue entry).

4. **`app/Console/Commands/PruneDeletedDocumentsCommand`** — bulk prune
   wrapped in a Flow with `--dry-run` mapping to laravel-flow's native
   dry-run mode (no DB writes, audit-only).

5. **`config/kb.php`** — `flow.enabled`, `flow.persistence_enabled`
   (off by default — keeps in-memory v0.1 path for SQLite tests),
   `flow.queue` (default `default`).

6. **Database migrations** — publish laravel-flow's `flow_runs` /
   `flow_steps` / `flow_audit` migrations (v0.2 ships them). They
   land alongside AskMyDocs's existing migration set, gated by
   `flow.persistence_enabled`.

**Tests required:**

- Feature: ingest a doc that fails on `generate_embeddings` step —
  assert file removed + document soft-deleted + chunks deleted +
  cache entries rolled back, all via the Flow's compensation chain.
- Feature: `kb:replay <run-id>` (laravel-flow v0.2 CLI) on a failed
  ingestion — assert the new linked run completes successfully and
  the original failed run is preserved.
- E2E: admin maintenance page surfaces failed Flow runs from
  `flow_runs` (read-only — execution remains via Artisan / API).
- Architecture: every multi-step service that does file writes +
  DB writes must be expressible as a Flow definition (gradual
  migration — gate via a code-level annotation or selective
  enforcement).

**Upstream readiness gates:**

- laravel-flow v0.2 ships persistence layer + queue support
  (Macro Tasks 2 + 3 in the package's enterprise plan). AskMyDocs
  integration depends on v0.2 stabilization.
- v0.3 (approval gates + webhooks) is NOT required for v4.1 — those
  features support different use cases (human-in-the-loop workflows
  outside of the kb ingestion path). v4.2 candidate for promotion
  workflow approval gates.

**Estimated v4.1 effort:** ~4 sub-tasks (one per flow conversion +
config + migration). Total ~20-25 R36 cycles.

---

### `padosoft/eval-harness` (W6) — STATUS: 🔴 Scaffold available (v0.1.0 tag, pending Packagist); v4.1 integration target

**Why this lands third** — AskMyDocs has zero RAG retrieval
regression coverage today. Any prompt change, reranker tweak, or
embedding model swap can silently degrade answer quality. The
eval-harness gives a CI-gated regression suite without building
RAG-specific evaluation infrastructure.

#### v4.1 integration scope (uses eval-harness v0.2 features)

**Touch points in `app/` + new directories:**

1. **`tests/Eval/datasets/canonical-rag-golden.yml`** — golden Q&A
   dataset for canonical KB queries. Each sample defines:
   - Input query (e.g., "What's the canonical decision on cache
     invalidation?")
   - Expected answer text (or substring match)
   - Expected citations (specific `doc_id` or slug)
   - Tags: `canonical`, `decision-doc`, etc. (cohort grouping)

2. **`tests/Eval/datasets/non-canonical-rag-baseline.yml`** — same
   shape, queries against the non-canonical KB to baseline the
   canonical boost.

3. **`tests/Eval/Metrics/CitationRetrievalMetric.php`** — custom
   metric (extends eval-harness's `Metric` interface) asserting that
   the response cites the expected `doc_id` set. Uses cohort
   grouping by `metadata.tags` (eval-harness v0.2) to surface
   per-document-type retrieval quality separately.

4. **`app/Console/Commands/EvalRagCommand.php`** — wraps
   `eval-harness:run tests/Eval/datasets/canonical-rag-golden.yml`
   with AskMyDocs-specific defaults: `--registrar=AskMyDocs\Eval\AskMyDocsRegistrar`,
   `--metrics=ExactMatch,CosineEmbedding,CitationRetrievalMetric`,
   output to `storage/eval-reports/`.

5. **`.github/workflows/eval.yml`** — new CI job that runs
   `php artisan eval:rag --json --out=eval-report.json` on every
   PR touching `app/Services/Kb/`, `resources/views/prompts/`, or
   `config/ai.php`. Non-blocking initially (status check posts
   results as PR comment); becomes blocking after baseline
   stabilization.

6. **`config/eval.php`** — `eval.providers.judge` (the LLM provider
   used by `LlmAsJudgeMetric`), `eval.cost_budget_eur` (cap on
   per-run LLM spend for live evals), `eval.adversarial_enabled`
   (opt-in flag for v4.2 adversarial datasets per eval-harness v0.3).

**Tests required:**

- The eval suite IS the test — `eval:rag` exit code 0 on green
  baseline, non-zero on regression.
- Add a `tests/Eval/EvalCommandTest.php` (PHPUnit) that asserts
  `eval:rag` runs against the deterministic test dataset without
  hitting external LLMs (uses `LlmAsJudgeMetric` in stub mode).

**Upstream readiness gates:**

- eval-harness v0.2 ships parallel batch queues + Horizon-ready
  execution (Macro Task 3 in the package's roadmap). AskMyDocs
  integration starts when v0.2 ships (Git tag minimum).
- v0.3 adversarial harness (prompt injection, jailbreak, PII leak
  red-teaming) is a v4.2 candidate — at that point AskMyDocs adds
  `eval:adversarial` to the CI matrix as a separate non-blocking
  gate.

**Estimated v4.1 effort:** ~3 sub-tasks (golden dataset + custom
metric + Artisan command + CI workflow). Total ~10-15 R36 cycles.

---

### `padosoft/askmydocs-mcp-pack` (v7.0/W1) — STATUS: ✅ v1.0.0 shipped 2026-05-15 (W1.A); host integration W1.B in-flight

Framework-agnostic Model Context Protocol plumbing for Laravel. Extracted
from the inline `app/Mcp/Client/*` services that AskMyDocs grew in v5.0,
so any Laravel AI app can drive a multi-turn tool-calling loop against
upstream MCP servers without re-inventing the orchestrator, transports,
or audit trail.

#### v7.0/W1.A — package extraction (shipped)

PR #1 → tag v1.0.0 → GitHub Release + Packagist live. What's in it:

- **6 contracts** (`McpToolContract`, `McpServerContract`,
  `McpServerRegistryContract`, `McpToolAuthorizerContract`,
  `McpHostBridgeContract`, `McpTransportContract`).
- **Multi-turn tool-calling orchestrator** (`McpToolCallingService`) —
  bounded by `mcp-pack.tool_calling.max_iterations`, kill-switch gated
  via `MCP_PACK_TOOL_CALLING_ENABLED`.
- **Cached handshake service** — `initialize` + `tools/list` cached
  per (tenant, server) for 5 min default.
- **Hash-only audit trail** — `mcp_tool_call_audit` migration stores
  SHA-256 of input + result, NOT raw payloads; honors
  `mcp-pack.audit_model` override for host subclassing.
- **Two transports** — `HttpJsonRpcTransport` (Laravel HTTP client)
  and `StdioJsonRpcTransport` (Symfony Process; single-shot per
  request, persistent stdio sessions planned for v1.1).
- **Built-in defaults (development scaffolding — NOT for production)**:
  `NullMcpHostBridge` throws loudly when not bound; `NullMcpToolAuthorizer`
  allows every tool (the host MUST swap this in production — it is the
  prototype-only authorizer); `InMemoryMcpServerRegistry` for tests +
  config-driven hosts.
- **Console** — `php artisan mcp-pack:ping {server-id?} --tenant=acme`
  walks the registry and prints a per-server status table.
- **42 tests** across the (PHP 8.3 × {Laravel 11, 12, 13}) +
  (PHP 8.4 × {Laravel 11, 12, 13}) + (PHP 8.5 × Laravel 13)
  combinations — 7 cells. The PHP 8.5 row is restricted to Laravel 13
  because earlier Laravel majors are not yet published as PHP 8.5
  compatible at the time of release.

#### v7.0/W1.B — AskMyDocs host integration (in-flight)

The work that flips AskMyDocs from "inline `app/Mcp/Client/*`" to
"depends on `padosoft/askmydocs-mcp-pack ^1.0`":

1. `composer require padosoft/askmydocs-mcp-pack:^1.0` — declare the dependency.
2. **Delete inline** `app/Mcp/Client/{McpClientBridge, McpHandshakeService,
   McpToolAuthorizer, McpToolCallingService, ToolInvoker, Registry/McpServerRegistry}.php`.
   The `Kb*Tool` classes under `app/Mcp/Tools/*` and
   `app/Mcp/Servers/KnowledgeBaseServer.php` STAY in the host because
   they depend on `App\Services\Kb\*` and `App\Models\*`.
3. **Host bridge** — implement `App\Mcp\MyHostBridge` against
   `App\Ai\AiManager` (~30 lines, recipe in the package README).
   Bind it to `McpHostBridgeContract` in `AppServiceProvider`.
4. **Registry adapter** — port `McpServerRegistry` to implement
   the package's `McpServerRegistryContract` on top of the existing
   `mcp_servers` Eloquent model. Bind it.
5. **Authorizer adapter** — port `McpToolAuthorizer` to implement
   `McpToolAuthorizerContract`. Bind it.
6. **Audit-model coexistence** — the host's `mcp_tool_call_audit`
   schema retains `input_json_redacted` (full redacted-payload audit
   for AskMyDocs's operator forensics) on top of the SHA-256
   `input_hash` column the package writes. Configure
   `mcp-pack.audit_model` to point at the host's
   `App\Models\McpToolCallAudit` subclass, which fills BOTH columns
   from within `creating()` so the package's hash-only contract is
   preserved while the host's richer audit row is satisfied. No
   migration churn — the redacted-payload column exists since v5.0
   and stays.
7. **Container rewiring** — every controller / chat handler that
   currently resolves `App\Mcp\Client\McpToolCallingService` should
   resolve `Padosoft\AskMyDocsMcpPack\Services\McpToolCallingService`
   instead.
8. **Regression tests** — the existing MCP coverage MUST stay green:
   `tests/Feature/Api/McpInternalAuthControllerTest.php` (sidecar
   auth), `tests/Feature/Api/Admin/McpServersAdminControllerTest.php`
   (server CRUD), and `tests/Feature/Api/Admin/McpToolCallAuditControllerTest.php`
   (audit list). New tests added under `tests/Feature/V7/` cover the
   host-bridge translation layer (provider → orchestrator → tool
   round-trip) and the audit-model subclass behaviour.
9. **Playwright** — three specs already assert the admin SPA contract
   end-to-end: `frontend/e2e/admin-mcp-super-admin.spec.ts` (server
   CRUD), `admin-mcp-tools-super-admin.spec.ts` (tool list +
   handshake), `admin-mcp-tools-viewer.spec.ts` (read-only viewer
   role). The contract is preserved across the package swap; smoke
   the chat-with-tools flow under the new orchestrator path and
   confirm zero diffs in these specs.

Acceptance gate: full PHPUnit + Vitest + Playwright green AND zero
diffs in `app/Mcp/Tools/*` / `app/Mcp/Servers/KnowledgeBaseServer.php`
(they ride on top of the package without rewriting).

---

### `padosoft/laravel-patent-box-tracker` (W4) — STATUS: ✅ External runner by design

**No AskMyDocs `app/` integration is planned, ever.** The standalone-agnostic
architecture rule (R37 in `CLAUDE.md`) requires that AskMyDocs USES the
packages but never hard-depends on the tracker — installing AskMyDocs
should not pull in a Patent Box dossier generator that's only relevant
to Italian operators in the `documentazione_idonea` regime.

The dogfood pattern stays exactly as today:

1. AskMyDocs ships `tools/patent-box/2026.yml` — the dossier config
   template (placeholder values for P.IVA, repo paths, SIAE/UIBM IDs).
2. Operators install `padosoft/laravel-patent-box-tracker` in a
   **separate Laravel project** they control.
3. They run `php artisan patent-box:cross-repo
   /abs/path/to/AskMyDocs/tools/patent-box/2026.yml` against their
   local clones of AskMyDocs + the four sister packages.
4. The tracker emits the audit-grade PDF + JSON dossier suitable
   for filing.

**No version of v4.x changes this contract.** If a future v5+ pivots
to bundling the tracker, that requires an ADR overriding the
standalone-agnostic rule, with explicit rationale for why the
operator-managed external pattern is no longer sufficient.

---

## Per-version delivery plan

| AskMyDocs version | Target date | Sister packages integration deliverables |
|---|---|---|
| **v4.0.x** (current train) | ongoing | Patch-level fixes only (composer constraints, docs, latent bugs surfaced by Copilot review). No new sister-package integration. |
| **v4.1.0** | TBD — gated on package readiness | All three pending integrations land in a single major: pii-redactor middleware + chat persistence redaction (v0.2 of the package); laravel-flow saga conversion of `IngestDocumentJob` + canonical promotion + bulk delete (v0.2 of the package); eval-harness golden dataset + RAG regression CI (v0.2 of the package). Each integration is a sub-task on `feature/v4.1` per R37; once-per-major merge to main when all three close. |
| **v4.2.0** | TBD — eval-harness v0.3 + flow v0.3 dependent | adversarial regression suite via `eval:adversarial`; promotion-pipeline approval gates via flow v0.3 ApprovalGate (KbPromotionController POST `/promote` becomes a flow with optional human-approval pause for high-impact canonical writes). |

## Ordering rationale

1. **pii-redactor first** — single largest production risk surface
   (GDPR/PII exposure on chat retention). Smallest implementation
   surface (one middleware + one config + token-map table). Lowest
   blast radius if rolled back (opt-in flag).
2. **laravel-flow second** — improves robustness of an existing
   working subsystem (ingestion). No user-visible behaviour change
   on the happy path; failure path becomes recoverable. Bigger
   surface (touches 3 services + 1 command).
3. **eval-harness third** — quality / regression guardrail.
   Doesn't change runtime behaviour; ships as CI infrastructure.
   Last because it benefits most from the prior two integrations
   being stable (so the regression baseline reflects the production
   pipeline, not in-flight changes).

## Out of scope for v4.x

- **Migration helpers from spatie/laravel-workflow / Symfony Workflow
  to laravel-flow** — laravel-flow ships these in its v1.0 (Macro
  Task 6); AskMyDocs has no historical workflow library to migrate
  from, so this is just consumed for free when the package matures.
- **Companion dashboard for laravel-flow / eval-harness** — both
  packages ship companion dashboard apps in their v1.0 (Macro Task 5
  in laravel-flow, Macro Task 6 report API + separate UI package in
  eval-harness). AskMyDocs's existing admin SPA may surface the
  read-only views via the report APIs in v4.2+, but won't bundle
  the dashboard apps.

## How to extend this roadmap

- **When a sister package ships a new version**: confirm the new
  capabilities, decide which AskMyDocs subsystem benefits, add the
  integration scope under the package's section, and bump the
  per-version table.
- **When integrating**: open a sub-branch off the relevant
  `feature/v4.x` integration branch (R37). One sub-task per
  integration touch point. R36 loop on each PR. Once all sub-tasks
  for a version close, merge `feature/v4.x` → main and tag the
  AskMyDocs version per R39.
- **When skipping**: if a sister package doesn't make sense to
  integrate at all, document the deliberate skip here (analogous to
  the patent-box-tracker external-by-design entry).
