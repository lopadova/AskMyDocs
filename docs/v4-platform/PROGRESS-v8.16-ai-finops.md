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
- **W1 Foundation (rebase #304 + bridge)** — 🟡
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
- **W2 Full SDK migration** — ⬜
  - [ ] INVESTIGATE laravel/ai OpenRouter native driver + FinOps HTTP cost capture (owner note)
  - [ ] Verify laravel/ai 0.6.8/0.7 breaking changes; bump pin
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
