# PROGRESS — v8.16 · AI FinOps  (live tracker, update as you go)

Authoritative plan: `PLAN-v8.16-ai-finops.md`. This file = current state for resume across context windows.

## Status legend
⬜ not started · 🟡 in progress · ✅ done · 🔵 blocked/waiting

## Branches (IMPORTANT naming gotcha)
- `feature/v8.16` (integration) — created from main @ 39f90876, pushed to origin.
- Sub-branches MUST use a **hyphen**: `feature/v8.16-W1-foundation`, `feature/v8.16-W2-...`, etc.
  A nested ref (integration name, then a path separator, then the wave) is refused by Git/GitHub once
  `feature/v8.16` already exists as a branch — the parent occupies the ref-file slot that the nested
  form would need as a directory. So every wave uses the flat hyphenated form. PR target stays
  `feature/v8.16` (R37).
- W1 branch: `feature/v8.16-W1-foundation` @ a2912d5b. PR **#314** → feature/v8.16.

## Local env
- composer + php are Herd `.bat` shims, available in **PowerShell** (NOT bash): `composer`,
  `php85`. Tests: `php85 -d memory_limit=1G vendor/bin/phpunit ...` (1G needed for full suite).
- FinOps packages installed locally: `padosoft/laravel-ai-finops v1.2.1` + `-admin v1.2.0`
  (from Packagist). `composer.lock` is GITIGNORED — CI does fresh `composer install` each run.
- #304 CLOSED (superseded by #314).

## Waves
- **W1 Foundation (rebase #304 + bridge)** — ✅ DONE. PR #314 merged into feature/v8.16 @
  **fbd46476** (2026-06-18). Tagged **v8.16.0-rc1** (prerelease) at that SHA. Closed after 6 Copilot
  rounds; the substantive catch was the laravel/ai pin conflict (see the MUST-FIX line below).
  W2 branch `feature/v8.16-W2-sdk-migration` created from fbd46476.
  - [x] W1 branch created (`feature/v8.16-W1-foundation` — hyphen form per the Branches note above — from origin/feature/v8.16)
  - [x] Merge origin/feature/v8.14 — only README.md conflicted (changelog); resolved newest-first. Committed 21410abc.
  - [x] Renumber v8.14 → v8.16 (README header+changelog, .env.example, CLAUDE.md §3, bootstrap/app.php comment)
  - [x] Verified all FinOps additions survived merge (scheduler slots, matrix rows, gates, alias, docs.json nav)
  - [x] composer install finops packages locally (v1.2.1 / v1.2.0)
  - [x] Local tests green: tests/Feature/FinOps + AdminAuthorizationMatrixTest = 15 tests, 276 assert
  - [x] R40 local critic (code-reviewer subagent; copilot-cli was 402/out-of-budget per #304) — fixed 1 must-fix (incomplete v8.14→v8.16 sweep in MaintenanceCommandController + 5 files) + changelog tense
  - [x] PR #314 opened → feature/v8.16, reviewer copilot-pull-request-reviewer
  - [x] ✅ **CI investigation RESOLVED (R22, artefact-first) — there was NO real failure.** The
        red `gh pr checks` rows were a **cancelled duplicate run**, not a test failure. tests.yml
        fires on BOTH `push` and `pull_request`; a `concurrency` group keyed on the head SHA cancels
        one of the two (documented in the workflow's own comment block). The `pull_request` run
        (`27720074143`, then `27724334032`) ran the FULL suite green (PHPUnit 8.3/8.4/8.5 + Playwright
        + Vitest + RAG, 8–18 min each). The `push` run (`27720068198`, conclusion=**cancelled**)
        fast-failed in 3–8 s because it was cancelled at startup — never ran a test. Verified both
        runs share head SHA f32b6c0a. `mergeStateStatus=UNSTABLE` is purely the cancelled run
        attached as a non-success check; merge is not blocked. Lesson: read `conclusion`
        (cancelled≠failure), not the `gh pr checks` fail label.
  - [x] Copilot R3 review: 1 nit (FinOpsAuthorize docblock — `isMethodSafe()` also treats TRACE as
        safe per RFC 7231/Symfony). Fixed in b0e97cda, re-requested review.
  - [x] ✅ **MUST-FIX (Copilot R4): composer `laravel/ai` pin conflict.** App pinned
        `laravel/ai >=0.6,<0.6.8` while finops 1.2.1 requires `^0.6.8 || ^0.7` — with no committed
        lock (gitignored), CI silently resolved finops **1.2.0** (loose) + laravel/ai 0.6.7, NOT the
        intended 1.2.1. Bumped pin to `^0.6.8` (minimal; defers `||^0.7` to W2). **This surfaced a
        SECOND break:** laravel/ai 0.6.8 added `array $providerOptions = []` to the
        `EmbeddingGateway::generateEmbeddings()` interface (changelog "provider options in
        embeddings"); `padosoft/laravel-ai-regolo` **1.0.0**'s gateway lacked it → signature-
        incompatibility fatal in RegoloProviderTest. **regolo v1.0.1** (published after 0.6.8) adds
        the param — bumped regolo pin `^1.0` → `^1.0.1`. Local slice (tests/Unit/Ai +
        tests/Feature/FinOps + AdminAuthorizationMatrixTest) = **127 tests / 516 assert GREEN** on
        laravel/ai 0.6.8 + regolo 1.0.1 + finops 1.2.1. NB regolo 1.0.1 constraint is `^0.6` (allows
        0.6.8, NOT 0.7) — W2's `||^0.7` widening will need a 0.7-compatible regolo release.
  - [x] Copilot R4 nit: AiCallMeter::meterEmbeddings now passes real `$response->embeddings` through
        (COW-cheap, faithful envelope). Pre-existing low-sev `symfony/yaml` advisories (CVE-2026-45133/
        45304/45305, fix in 8.0.12+) noted for a separate hardening pass — unrelated to this PR.
  - [ ] R36 cloud loop until 0 must-fix + CI green → auto-merge (R: auto-merge when ready)
  - [ ] tag v8.16.0-rc1 at the W1 closure SHA on feature/v8.16 (R39)
- **W2 Full SDK migration** — 🟡 IN PROGRESS (branch `feature/v8.16-W2-sdk-migration`)
  - **SCOPE DECISION:** stay on `laravel/ai ^0.6.8` for W2 (do NOT widen to `||^0.7`). regolo 1.0.1
    is `^0.6`-only; 0.6.8's native gateways suffice for the migration. The 0.7 jump stays gated on a
    regolo-0.7 release (separate follow-up). Pin bump already done in W1.
  - **OPEN CRITICAL QUESTION (investigating):** tool-calling. `app/Mcp/Client/McpToolCallingService.php`
    runs AskMyDocs's OWN tool loop — passes dynamic JSON-schema tools via `$options['tools']` into
    `chatWithHistory`, reads back `AiResponse->toolCalls`, executes via MCP, re-calls. Consumers:
    MessageController, MessageStreamController, WidgetOrchestratorService, HostBridge. The SDK normally
    OWNS the tool loop (executes PHP Tool classes), which conflicts. RegoloProvider template sidesteps
    this (tools:[]). A subagent is investigating whether the SDK can return RAW single-turn tool calls
    over dynamic JSON tools without auto-executing (verdict A mechanical / B hard-mismatch / C hybrid:
    no-tools+embeddings→SDK, with-tools→keep Http::). This determines the OpenAI/OpenRouter port shape.
  - [x] INVESTIGATE laravel/ai OpenRouter native driver + FinOps HTTP cost capture — DONE (see
        `W2-sdk-migration-findings.md`): all 4 providers have native drivers; FinOps captures real
        `usage.cost` via a global Http RESPONSE middleware (survives migration); 2 gates: actual_cost
        default-OFF + OpenRouter needs `usage:{include:true}` via agent providerOptions.
  - [x] Verify laravel/ai 0.6.8/0.7 breaking changes; bump pin — pin at ^0.6.8 (W1). 0.7 deferred.
  - [x] TOOL-CALLING VERDICT = (C) HYBRID (see W2-findings). Anthropic/Gemini full-SDK; OpenAI/
        OpenRouter SDK no-tools + Http tools. Metering hook ON by default (regolo proves it).
  - Per-provider migration commits (on branch, not pushed; one PR when complete):
    - [x] **Commit 1 — Anthropic** (50e6a81e): full SDK, no tools/embeddings. Shared `Concerns\SdkChat`
          trait + `Internal\SdkAnonymousAgent`. config SDK shape. AiCallMeter SDK_METERED_PROVIDERS
          += anthropic (data-provider test). 10 provider tests + 128 AI/FinOps slice green.
    - [x] **Commit 2 — Gemini**: full SDK chat + embeddings via SDK (`Embeddings::for()->generate()`
          in `SdkChat::embeddingsViaSdk`). Reshaped config to SDK shape (driver/key/url/models) +
          AiManager hasApiKey (gemini api_key→key) + EmbeddingCacheService model path
          (embeddings_model→models.embeddings.default) + AiManagerTest fallback keys. Added gemini to
          SDK_METERED_PROVIDERS (+ data-provider test). GeminiProviderTest rewritten as SDK-adapter
          contract. AI + FinOps slice = 129 tests green.
    - [ ] **Commit 3 — OpenAI**: SDK no-tools chat + SDK embeddings; KEEP raw-Http:: with-tools
          branch. AiManager: gate AiCallMeter to the tools path only (array_key_exists('tools')).
    - [ ] **Commit 4 — OpenRouter**: same hybrid + agent providerOptions usage.include (cost capture).
    - [ ] **Commit 5 — cleanup**: AiManager final metering gate, enable actual_cost, ADR reversing §6,
          R9 doc sweep of "raw Http::", .env.example, README/CLAUDE bridge wording.
  - [ ] Migrate OpenAI/Anthropic/Gemini/OpenRouter to SDK
  - [ ] Reshape config/ai.php; rewrite provider unit tests
  - [ ] auto_register on; retire AiCallMeter to fallback
  - [ ] ADR reversing §6 + R9 doc sweep
  - [ ] tag v8.16.0-rc2
- **W3 Streaming + server-side cost authority** — ⬜
  - [ ] Stream metering verified
  - [ ] chat_logs cost column (additive) + CostResolutionService at log time
  - [ ] ledger↔turn trace_id linkage
  - [ ] retire static cost_rates + FE computeMessageCost; FE reads server cost
  - [ ] tag v8.16.0-rc3
- **W4 MCP + SPA E2E + docs/GA** — ⬜
  - [ ] MCP read tools + registration-count test
  - [ ] Playwright E2E finops admin SPA
  - [ ] SPA asset build/publish in CI
  - [ ] docs-site + README roadmap flip + CLAUDE.md
  - [ ] merge feature/v8.16 → main; tag v8.16.0; Release

## Owner notes (do not lose)
- 2026-06-17: ALWAYS via laravel/ai SDK, forward standard. Reverse §6.
- 2026-06-17: OpenRouter — laravel/ai likely implements it natively; FinOps hooks the HTTP request to capture OpenRouter's extra returned info (usage.cost / billed cost) that laravel/ai doesn't capture. Investigate deeply at W2 before assuming a custom driver is needed.
- "costo token messo a caso" = static config/ai.php cost_rates + FE client-side compute; no server-side cost; fixed in W3.

## Log
- 2026-06-17: design approved; feature/v8.16 created; plan + progress committed.
