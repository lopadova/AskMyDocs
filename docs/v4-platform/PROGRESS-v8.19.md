# PROGRESS — v8.19 (live tracker, update as you go)

Authoritative plan: `~/.claude/plans/squishy-marinating-cocke.md`. This file = current state for resume
across context windows.

## Status legend
⬜ not started · 🟡 in progress · ✅ done · 🔵 blocked/waiting

## Branches
- `feature/v8.19` (integration) — created from `main` @ 9f543472 (includes v8.18 GA e89eebbb + #315 team-switcher), pushed.
- Sub-branches: `feature/v8.19-<name>`. PR target = `feature/v8.19` (R37).

## Local env
- PowerShell + Herd shims: `composer`, `php85`. Tests: `php85 -d memory_limit=1G vendor/bin/phpunit …`.
- Local package clones under `Ai/`: `laravel-ai-regolo`, `laravel-ai-finops`, `laravel-ai-guardrails`,
  `laravel-ai-guardrails-admin`, `spreadsheet-ai` (UX reference for W5).
- copilot-cli out of budget → R40 local gate is the `code-reviewer` subagent.
- composer.lock is gitignored → CI resolves fresh each run.
- Team/tenant switcher is now in main (#315) — new admin SPA pages must wire to it (skill team-scope-wiring).

## Pre-flight audit (done at plan time)
23 padosoft packages installed. `Laravel\Ai` namespace grep → only **regolo** + **finops** use the SDK in code.
guardrails core is born on `^0.8`. Everything else (eval-harness, pii-redactor, ai-act, evidence-risk, flow,
9 connectors, mcp-pack, all *-admin) does NOT reference the SDK → no release needed. Migration bounded to
regolo + finops.

## Waves

- **W1 — laravel/ai 0.8.1 platform migration** — ✅ (host code change = ZERO)
  - W1.0 break-change study — ✅ only break 0.6→0.8 = `TranscriptionGateway::generateTranscription()` gained
    `$providerOptions` (laravel/ai v0.7.0 #31; regolo diff #16 was the cheatsheet). Host uses chat+embeddings
    only, NO transcription → not affected.
  - W1.1 regolo — ✅ already published v1.2.1 (`^0.6|^0.7|^0.8.1`); host requires `^1.2.1`.
  - W1.2 finops — ✅ already published v1.4.0 (0.8-line verified); host requires `^1.4`.
  - W1.3 host bump — ✅ `composer.json laravel/ai ^0.6.8→^0.8.1`; `composer update` resolved a single
    `laravel/ai 0.8.1` cleanly (finops v1.3.0→v1.4.0, regolo v1.0.1→v1.2.1). Tests green on 0.8.1:
    `tests/Unit/Ai` 134 OK + `tests/Feature/FinOps` + chat + chatlog 49 OK. `LaravelAiPinTest` flipped to
    assert the 0.8 line. ADR 0016 written. No host SDK code change needed.
- **W2 — guardrails core (enforce on chat, tri-surface, RBAC)** — 🟡 impl done, testing
  - `composer require padosoft/laravel-ai-guardrails:^1.1.0` (v1.1.0, resolves on 0.8.1). 7 package migrations
    published to `database/migrations/` + SQLite mirrors in `tests/database/migrations/` (0001_01_01_000055-000061).
  - `config/ai-guardrails.php` (host override): stores→database; `api` ON behind R32 stack
    (auth:sanctum + tenant.authorize + `guardrails.authorize`), prefix `api/admin/ai-guardrails`; output_handler
    tuned for a markdown RAG answer (sanitize_html=false — FE markdown renderer is the XSS boundary; redact_pii=false
    — AskMyDocs owns PII; neutralize_markdown=true enforce — defang exfil links).
  - Enforcement wired into `KbChatController` via host adapter `app/Services/Guardrails/ChatGuardrails.php`
    (the package controls are laravel/ai AGENT middlewares; the host chat path isn't an agent loop, so the
    adapter mirrors their screen+audit / sanitize+stat the way the package CLI does). Input block → refusal
    (reason `blocked_by_guardrails`, lang en+it), never 500 (R26/R27). Mode-aware (enforce/monitor/off), R43
    both-states gated on `enabled` flags.
  - Gates `viewAiGuardrails`/`manageAiGuardrails` + `GuardrailsAuthorize` middleware (method-aware) + alias
    in bootstrap/app.php AND tests/TestCase.php. Core SP + host config registered in TestCase.
  - Tri-surface (R44): PHP (package commands + adapter), HTTP (core API behind RBAC + R32 matrix row
    `/api/admin/ai-guardrails/overview`), MCP `KbGuardrailsInsightsTool` (roster **32→33**).
  - Guardrails tables are GLOBAL security infra (no tenant_id, like embedding_cache) — not in the R31 model
    lists; isolation via admin RBAC.
  - Tests green: GuardrailsChatEnforcementTest 5 (block→refusal+audit, R43 input/output OFF, exfil neutralized,
    degraded-audit-store still refuses), GuardrailsInsightsToolTest 2 (posture + R43 OFF), MCP registration 33,
    R32 matrix incl. the guardrails write-method boundary (admin 403 / super-admin pass on PUT /settings).
  - **R45 doc-site** (`docs-site/ai-guardrails.mdx`) is authored in **W6** with the rest of the cycle's docs
    (every wave defers its doc-site page to the single docs wave); README/changelog/MCP-count also land in W6.
- **W3 — guardrails-admin SPA mount (RBAC, default-OFF, E2E)** — ⬜
- **W4 — Agentic Knowledge Reports backend (agentic columns + governance + library)** — 🟡 impl done, testing
  - `AgentKind` enum (extract/graph/verify) — the agentic dimension, orthogonal to FormatType; absent → extract
    (backward-compatible, every pre-v8.19 review unchanged — 48 existing tabular tests stay green).
  - `GovernanceColumnResolver` (deterministic, NO LLM): 10 metrics from the canonical graph (kb_edges) + doc
    columns — evidence_tier, frontmatter_completeness, canonical_status, is_canonical, incoming/outgoing_edges,
    graph_connectivity, is_orphan, supersession_status, staleness_days. Tenant-scoped (R30), R14 grey/red on
    non-canonical/unknown.
  - `TabularReviewExtractor` extended: `agent: graph` → resolver (LLM-free); `agent: verify` → a bounded second
    LLM pass that downgrades a flag (green→yellow/else→red) when the value isn't supported by cited evidence
    (R14: never worse than extract; verify failure keeps the original cell).
  - Flagship preset "Canonical KB Governance Audit" (#16) seeded via `BuiltInWorkflowSeeder` — 8 graph governance
    columns + 1 verify contradiction column (the ready-made library is now 16 templates).
  - Validation: `agent` + `metric` added to Store/Update TabularReview requests (metric required+enumerated for
    graph). Tri-surface (R44): PHP (extractor+seeder), HTTP (existing /api/admin/tabular-reviews/*), MCP
    `KbRunReportTool` (roster **33→34**, reads a report's matrix, R30/R43).
  - Tests: GovernanceColumnResolverTest 10, extractor agentic 2 (graph deterministic no-LLM + verify downgrade),
    RunReportToolTest 3 (matrix + R30 cross-tenant + R43 missing), MCP registration 34; full sweep 154 green.
- **W5 — Agentic Knowledge Reports FE (Glide grid + streaming + editor)** — ⬜
- **W6 — README + doc-site** — ⬜
- **GA — merge feature/v8.19 → main + tag v8.19.0** — ⬜

## Log
- 2026-06-21: plan approved (full scope locked); `feature/v8.19` created from main @ 9f543472; PROGRESS committed.
