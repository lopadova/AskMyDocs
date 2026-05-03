# Sister packages integration roadmap

> **Honest status as of v4.1 GA (2026-05-03)**
>
> Of the five `padosoft/*` sister packages shipped during the v4.0
> cycle, **two are now wired into AskMyDocs's `app/` runtime**:
> `padosoft/laravel-ai-regolo` (since v4.0 W2) and
> `padosoft/laravel-pii-redactor` (since v4.1 W4.1, this release).
> The pii-redactor integration ships at the **four observable
> touch-points** documented in the v4.1 section below — chat-message
> middleware, embedding-cache pre-redact, AI-insights snippet
> sanitiser, and the operator-driven detokenize endpoint — with every
> knob default-off so v4.0 hosts upgrading to v4.1 see byte-identical
> behaviour until they explicitly opt in.
> The remaining two (`laravel-flow`, `eval-harness`) stay in
> `require-dev` (separate v4.2/v4.3 integration cycles). Their package
> repos are v0.1.0 scaffolds — production-grade implementations land
> alongside the AskMyDocs integration per the per-package timelines
> below.
> `padosoft/laravel-patent-box-tracker` is the only sister package not
> declared in AskMyDocs's `composer.json` at all — by design, operators
> install it in their own Laravel project (R37 standalone-agnostic; see
> `tools/patent-box/2026.yml` and the README's Patent Box section).
>
> The v4.0.0 GA release notes and W5/W6/W7 closure docs described these
> packages as "shipped engines"; v4.0.2's docs honesty pass corrected
> the language to reflect the real shipped state without rewriting the
> historical changelog entries. v4.1 adds the first production
> integration of one of those scaffolds (pii-redactor) per the same
> honest-status convention.

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
