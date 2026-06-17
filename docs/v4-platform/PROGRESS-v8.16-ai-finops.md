# PROGRESS — v8.16 · AI FinOps  (live tracker, update as you go)

Authoritative plan: `PLAN-v8.16-ai-finops.md`. This file = current state for resume across context windows.

## Status legend
⬜ not started · 🟡 in progress · ✅ done · 🔵 blocked/waiting

## Branches (IMPORTANT naming gotcha)
- `feature/v8.16` (integration) — created from main @ 39f90876, pushed to origin.
- Sub-branches MUST use a **hyphen**, not a slash: `feature/v8.16-W1-foundation` (NOT
  `feature/v8.16/W1-foundation`). Git/GitHub refuse a nested ref when the parent
  `feature/v8.16` already exists as a branch (ref file vs dir conflict). Use
  `feature/v8.16-Wn-...` for every wave. PR target is still `feature/v8.16` (R37).
- W1 branch: `feature/v8.16-W1-foundation` @ a2912d5b. PR **#314** → feature/v8.16.

## Local env
- composer + php are Herd `.bat` shims, available in **PowerShell** (NOT bash): `composer`,
  `php85`. Tests: `php85 -d memory_limit=1G vendor/bin/phpunit ...` (1G needed for full suite).
- FinOps packages installed locally: `padosoft/laravel-ai-finops v1.2.1` + `-admin v1.2.0`
  (from Packagist). `composer.lock` is GITIGNORED — CI does fresh `composer install` each run.
- #304 CLOSED (superseded by #314).

## Waves
- **W1 Foundation (rebase #304 + bridge)** — 🟡
  - [x] W1 branch created (`feature/v8.16/W1-foundation` from origin/feature/v8.16)
  - [x] Merge origin/feature/v8.14 — only README.md conflicted (changelog); resolved newest-first. Committed 21410abc.
  - [x] Renumber v8.14 → v8.16 (README header+changelog, .env.example, CLAUDE.md §3, bootstrap/app.php comment)
  - [x] Verified all FinOps additions survived merge (scheduler slots, matrix rows, gates, alias, docs.json nav)
  - [x] composer install finops packages locally (v1.2.1 / v1.2.0)
  - [x] Local tests green: tests/Feature/FinOps + AdminAuthorizationMatrixTest = 15 tests, 276 assert
  - [x] R40 local critic (code-reviewer subagent; copilot-cli was 402/out-of-budget per #304) — fixed 1 must-fix (incomplete v8.14→v8.16 sweep in MaintenanceCommandController + 5 files) + changelog tense
  - [x] PR #314 opened → feature/v8.16, reviewer copilot-pull-request-reviewer
  - [ ] 🔵 **CI on #314 RED — INVESTIGATE FIRST (R22, artefact-first).** PHPUnit 8.3/8.4/8.5 + Vitest
        FAIL on the FULL suite (local targeted finops+matrix passed, so it's something the full
        suite/CI surfaces — possibly the laravel/framework 13.8→13.16 resolve, ai_finops_* migration
        interaction on the full SQLite run, or a pre-existing main failure). Pull `gh run view
        --job <id> --log-failed`, the playwright-report artefact, and the inline Laravel log dump
        BEFORE editing. Run IDs at hand-off: failing 27719790194; a re-run 27719854163 was pending.
        Also confirm whether main itself is green (rule out inherited failure) by checking the last
        main CI run.
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
