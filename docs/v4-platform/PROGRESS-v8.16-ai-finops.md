# PROGRESS — v8.16 · AI FinOps  (live tracker, update as you go)

Authoritative plan: `PLAN-v8.16-ai-finops.md`. This file = current state for resume across context windows.

## Status legend
⬜ not started · 🟡 in progress · ✅ done · 🔵 blocked/waiting

## Branches
- `feature/v8.16` (integration) — created from main @ 39f90876.
- `feature/v8.16/W1-foundation` — ⬜

## Waves
- **W1 Foundation (rebase #304 + bridge)** — 🟡
  - [x] W1 branch created (`feature/v8.16/W1-foundation` from origin/feature/v8.16)
  - [x] Merge origin/feature/v8.14 — only README.md conflicted (changelog); resolved newest-first. Committed 21410abc.
  - [x] Renumber v8.14 → v8.16 (README header+changelog, .env.example, CLAUDE.md §3, bootstrap/app.php comment)
  - [x] Verified all FinOps additions survived merge (scheduler slots, matrix rows, gates, alias, docs.json nav)
  - [ ] composer install finops packages locally (in progress)
  - [ ] Local tests green (phpunit finops + matrix + vitest)
  - [ ] R40 local critic loop clean
  - [ ] PR opened → feature/v8.16, R36 loop, auto-merge
  - [ ] tag v8.16.0-rc1
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
