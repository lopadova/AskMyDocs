# v4.1 Week 4.1 closure — 2026-05-03

W4.1 deliverable per `project_v40_week_sequence`: **integrate
`padosoft/laravel-pii-redactor` v1.1 into AskMyDocs as the chat-message
PII redaction layer, the embedding-cache pre-redact layer, the
AI-insights snippet sanitiser, and the operator-driven detokenisation
endpoint — all gated by per-touch-point config knobs that default
OFF, so existing v3 / v4.0 hosts upgrading to v4.1 see zero behaviour
change until they explicitly opt in.**

The W4.1 design followed the four-touch-point integration matrix from
`docs/v4-platform/INTEGRATION-ROADMAP-sister-packages.md` and the EU
country-pack architecture locked into the package itself during the
v0.2→v1.1 cycle (PRs #2..#7 on `padosoft/laravel-pii-redactor`).
Every locked decision held through delivery.

## Sub-tasks shipped

| Sub-task | PR | Merge SHA on `feature/v4.1` | Outcome |
|---|---|---|---|
| W4.1.A — composer + config + .env scaffold | #103 | `8a2c3a4` (sample) | `composer require padosoft/laravel-pii-redactor:^1.1` (moved from `require-dev` in pre-W4.1 spike), `config/kb.php` ships the `pii_redactor` integration block (5 knobs, all default-false), `.env.example` carries the `KB_PII_*` + `PII_REDACTOR_*` lines (commented-out per fail-safe), `bootstrap/providers.php` lists `PiiRedactorServiceProvider` explicitly as a Windows / Herd auto-discovery safety net |
| W4.1.B — `redact-chat-pii` middleware + chat-route binding | #104 | `8b2abc4` | New `App\Http\Middleware\RedactChatPii` — constructor-injected `RedactorEngine`, `handle()` checks both gates (`kb.pii_redactor.enabled` AND `kb.pii_redactor.persist_chat_redacted`), reads the request `content` field, runs `engine->redact()`, merges the redacted string back into the request before the controller sees it. Default no-op. Alias `redact-chat-pii` registered in `bootstrap/app.php`, attached to `POST /conversations/{conversation}/messages` (sync) AND `/messages/stream` (SSE — stacked on top of `auth.sse`). Architecture test `tests/Architecture/PiiRedactionMiddlewareScopeTest.php` pins the binding scope to EXACTLY those two routes (admin/curator/promotion routes are excluded by name). 5 feature tests cover pass-through / both-gates-on / empty / null content. |
| W4.1.C — embedding-cache pre-redact (mask before hash + provider call) | #105 | `06b4c4c` | `EmbeddingCacheService::generate()` now runs `MaskStrategy` on every input string BEFORE the SHA-256 hash that keys the cache row AND BEFORE the text reaches the embedding provider's HTTP call, when both `kb.pii_redactor.enabled` AND `kb.pii_redactor.redact_before_embeddings` are true. Why mask not tokenise: embeddings are one-way (no detokenise round-trip needed), mask is stable (cache hit-rate preserved across re-ingestion), and mask carries no per-tenant secret (multi-tenant cache reuse stays intact). 3 feature tests cover default-off / master-switch precedence / both-knobs-on (asserts `text_hash` matches SHA-256 of the masked text NOT the original, and provider args do NOT contain raw email). |
| W4.1.D — AI-insights snippet redact + LogViewer detokenize action | #106 | `c24ceeb` | `AiInsightsService::coverageGaps()` masks PII out of every chat sample question BEFORE clustering, when both `kb.pii_redactor.enabled` AND `kb.pii_redactor.redact_insights_snippets` are true — short-circuits leakage to BOTH the LLM call AND the snapshot persisted into `admin_insights_snapshots.payload_json`. New `POST /api/admin/logs/chat/{id}/detokenize` endpoint round-trips a tokenised chat-log row back to the original PII text — gated by 422 (when strategy is not `tokenise`) AND 403 (when caller lacks the Spatie permission named in `kb.pii_redactor.detokenize_permission`, default `pii.detokenize`). Every 200 / 403 writes an `admin_command_audit` row; the 422 strategy-mismatch preflight is intentionally not audited (config-stage error, not an operator action). 6 feature tests cover the insights pre-redact gate + the detokenize 422/403/200 contract. R30 tenant-scoped on every `chat_logs` read. |
| W4.1.E — closure status doc + end-to-end architecture test + README/Changelog refresh for v4.1.0-rc1 | this PR | TBA | This document; `tests/Architecture/PiiRedactorIntegrationScopeTest.php` enforcing the four touch-point invariants on every CI run; README "Key Features" + "## Changelog" sections updated with the v4.1.0-rc1 entry per R39 |

## Acceptance gates passed

- Every sub-task PR converged to **0 outstanding must-fix** through
  the R36 loop:
  - PR #103 (W4.1.A): 1 cycle (formal review clean, no inline comments).
  - PR #104 (W4.1.B): 2 cycles — fix on cycle 1 was the detokenise
    round-trip explicit assertion in `RedactChatPiiTest.php:130`.
  - PR #105 (W4.1.C): 1 cycle (formal review clean, "Copilot reviewed
    2 out of 2 changed files in this pull request and generated no
    comments").
  - PR #106 (W4.1.D): 2 cycles — cycle 1 brought 4 must-fix + 2 doc
    drifts (R30 tenant scope on `coverageGaps()` + `chatDetokenize()`,
    config-driven 403 message, non-vacuous snapshot-content
    assertion, docblock + route-comment alignment); user-bot
    confirmed all 6 in commit `82c3332`.
- CI matrix on every sub-task PR — PHPUnit (PHP 8.3 / 8.4 / 8.5 × 2)
  + Vitest (× 2) + Playwright E2E (× 2) — converged GREEN before
  merge on every PR.
- Test posture post-W4.1.D merge: **1076 PHPUnit tests / 3316
  assertions** across the suites:
  - Unit: 420 / 1150 assertions
  - Feature: 645 / 2112 assertions (W4.1 added: 14 cases / 56
    assertions across 4 new feature test files)
  - Architecture: 11 / 54 assertions (W4.1 added: 2 architecture
    cases pinning the middleware binding scope; W4.1.E adds the
    end-to-end integration scope test)
- Default-off invariant maintained on every knob: `kb.pii_redactor.enabled`,
  `kb.pii_redactor.persist_chat_redacted`,
  `kb.pii_redactor.redact_before_embeddings`,
  `kb.pii_redactor.redact_insights_snippets`,
  `kb.pii_redactor.detokenize_permission` (config-driven name) — every
  knob ships `false` / `'pii.detokenize'` and v3 / v4.0 hosts upgrading
  see ZERO behavioural change unless they explicitly flip the env vars.
- R30 cross-tenant isolation enforced on every new query path that
  touches `chat_logs` (a tenant-aware table via `BelongsToTenant`):
  `coverageGaps()` and `chatDetokenize()` both scope reads via
  `->forTenant(app(TenantContext::class)->current())` before
  `->get()` / `->findOrFail()`. The architecture test enumerates the
  four touch-points and the controller / service files they live in
  so a future regression that drops the scope is caught at architecture-
  test time.
- R10 canonical-content invariant maintained: the middleware binding
  is architecturally pinned to EXACTLY the two chat-message routes
  (`PiiRedactionMiddlewareScopeTest`); curator-supplied ingest /
  promotion / delete routes are NEVER touched, because redacting
  curator content would silently corrupt the canonical KB pipeline
  (`DocumentIngestor` would hash the redacted bytes).

## The four observable touch-points (recap)

W4.1 wires the package into AskMyDocs at exactly four observable
touch-points. Every other code path is structurally unchanged.

1. **Chat-message persistence** (W4.1.B) — middleware on
   `POST /conversations/{conversation}/messages` (sync + SSE). Reads
   `request.content`, runs `RedactorEngine::redact()` per the
   tenant-configured strategy (Tokenise / Mask / Hash / Drop), merges
   the redacted string back into the request. Affects what the
   controller persists into `chat_logs.question` /
   `messages.content` AND what the LLM sees. Gates:
   `kb.pii_redactor.enabled` AND `kb.pii_redactor.persist_chat_redacted`.
2. **Embedding cache + provider call** (W4.1.C) —
   `EmbeddingCacheService::generate()` masks every input BEFORE the
   SHA-256 cache hash and BEFORE the embedding provider's HTTP call.
   Mask strategy (one-way, stable). Gates:
   `kb.pii_redactor.enabled` AND `kb.pii_redactor.redact_before_embeddings`.
3. **AI-insights snippet sanitiser** (W4.1.D) —
   `AiInsightsService::coverageGaps()` masks every chat sample
   question BEFORE clustering. Short-circuits leakage to BOTH the
   LLM call AND the snapshot persisted into
   `admin_insights_snapshots.payload_json`. Gates:
   `kb.pii_redactor.enabled` AND `kb.pii_redactor.redact_insights_snippets`.
4. **Operator-driven detokenisation** (W4.1.D) —
   `POST /api/admin/logs/chat/{id}/detokenize` round-trips a
   tokenised chat-log row back to the original PII text. Gates:
   strategy must be `tokenise` (else 422); caller must carry the
   Spatie permission named in `kb.pii_redactor.detokenize_permission`
   (else 403); every 200 / 403 writes an `admin_command_audit` row.

## Lessons captured during W4.1

The lessons below are codified back into agent memory and into
`.claude/skills/` so future PRs inherit the fixes.

- **Package SP `loadMigrationsFrom` clashes with host-published
  mirrors of the same migration.** First attempt at W4.1.C published
  the package's `pii_token_maps` migration into `database/migrations/`
  and mirrored it under `tests/database/migrations/`; both prod and
  Testbench `migrate:fresh` then threw `SQLSTATE[HY000] table
  "pii_token_maps" already exists` because the package SP
  auto-loads its own migration. The right pattern: do NOT publish a
  duplicate. Let the package SP handle migrations via
  `loadMigrationsFrom()` and document this in the integration roadmap.
- **R30 tenant-scope on every new query against tenant-aware tables.**
  Initial W4.1.D landed `ChatLog::query()` reads in two places
  (`AiInsightsService::coverageGaps()` and
  `LogViewerController::chatDetokenize()`) without
  `->forTenant(...)`. Copilot caught both as cross-tenant leaks. Both
  now scope explicitly. Reinforces the standing R30 rule —
  `chat_logs` is `BelongsToTenant`, every read MUST be tenant-scoped,
  the trait makes the WRITE side automatic but the READ side stays
  the query author's responsibility.
- **Hard-coded permission names break config-driven gates.** First
  W4.1.D draft of `chatDetokenize()` had a 403 body reading
  "missing pii.detokenize permission" while the required permission
  is config-driven via `kb.pii_redactor.detokenize_permission`. A
  host overriding the permission name (e.g. `audit.unmask`) would
  see a misleading body. The fix: build the message from the
  resolved `$permission` variable. Generalises to: any user-facing
  message that refers to a config-driven name MUST be built from the
  resolved value, not a literal.
- **Test "PII not in payload" assertions can be vacuously-true** if
  the mocked LLM stub returns `sample_questions: []`. First W4.1.D
  draft of `test_both_knobs_on_masks_pii_before_llm_and_snapshot_payload`
  iterated over an empty array; Copilot caught it as a no-op
  assertion. Fix: the new `fakeLlmEchoingPromptQuestions()` stub
  parses the numbered question block out of the prompt and echoes
  it back as `sample_questions`, plus an explicit
  `assertGreaterThan(0, $totalSamples)` guard. Generalises to R16:
  test names and bodies must agree, and any assertion over a
  collection must guarantee the collection is non-empty.
- **Default-off invariant requires explicit verification per knob.**
  Every sub-task added a new `default_off_test_*` case verifying
  that with the master switch and the per-touch-point knob both
  false, the behaviour is observably identical to the
  non-integrated v4.0 baseline. Without these tests, a regression
  that flips a default to `true` would silently change behaviour
  for upgrading hosts.

## Production impact

W4.1 ships AskMyDocs **v4.1.0-rc1** from `feature/v4.1` per R39 (RC
tag at the closure SHA before the docs PR merges; final `v4.1.0` GA
tag fires after the integration PR `feature/v4.1` → `main` per R37).

The integration is **fully backward-compatible**: every knob defaults
off, every read path that doesn't enable a knob produces byte-identical
output to v4.0, every architecture test pins both the binding scope
(middleware on chat routes only) and the call-site invariants
(tenant-scoped reads, config-driven permission name, gated knobs).
Footprint: ~620 LOC of source / ~810 LOC of tests added across the
five sub-task PRs.

The chat-message middleware stack and the embedding-cache hot path
both gain one extra `RedactorEngine::redact()` call per request when
the relevant knob is on; the package's own benchmarks (v1.0+ README
§ Performance) cap that at ~3 ms for a 1000-character payload on the
default detector pack, well under the existing per-turn budget.

## Residual items parked for v4.2

Per the integration roadmap (`docs/v4-platform/INTEGRATION-ROADMAP-sister-packages.md`),
the following PII-related items are explicitly out of scope for v4.1
and tracked for v4.2:

- **NER-driver wiring** — the package supports an opt-in spaCy /
  Hugging-Face NER driver for free-form name detection beyond the
  regex/checksum-backed detectors. v4.1 ships with NER disabled by
  default; v4.2 will add a config-gated `KB_PII_NER_DRIVER` switch
  + a benchmarking harness so operators can choose between the
  faster regex-only path and the slower NER-augmented path with
  full visibility into the latency tradeoff.
- **Streaming-redact for SSE** — the W4.1.B middleware redacts the
  inbound `request.content` field before the controller starts
  streaming. The OUTBOUND SSE delta stream is currently unredacted
  (the LLM's response text reaches the SPA verbatim). v4.2 will add
  a stream-aware redactor that pipes each `text-delta` chunk through
  `RedactorEngine::redact()` before flushing to the client, with a
  per-chunk-budget guard so the redactor never stalls the stream.
- **Detokenise-bulk endpoint** — `chatDetokenize()` is single-row.
  v4.2 will add a `POST /api/admin/logs/chat/detokenize-bulk` action
  that accepts an array of ids (max 50 per call, rate-limited) for
  the case where an operator needs to unmask a paginated audit
  result; same permission gate, same audit shape.
- **Redactor-vs-classifier integration with `padosoft/laravel-flow`
  W5** — once `laravel-flow` v0.1 ships its tagging pipeline, the
  redactor will sit upstream of the flow classifier so PII is masked
  before the classifier sees the chat content.
