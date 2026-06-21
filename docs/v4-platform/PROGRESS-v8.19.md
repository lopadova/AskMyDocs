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

- **W1 — laravel/ai 0.8.1 platform migration** — 🟡 in progress
  - W1.0 break-change study (0.6→0.8; regolo's done migration as cheatsheet) — ⬜
  - W1.1 release regolo on ^0.8 — ⬜
  - W1.2 release finops on ^0.8 — ⬜
  - W1.3 host bump ^0.6.8→^0.8.1 + compat pass (4 providers + finops hook + regolo) + LaravelAiPinTest flip + ADR 0016 — ⬜
- **W2 — guardrails core (enforce on chat, tri-surface, RBAC)** — ⬜ (MCP 32→33)
- **W3 — guardrails-admin SPA mount (RBAC, default-OFF, E2E)** — ⬜
- **W4 — Agentic Knowledge Reports backend (agentic columns + governance + library)** — ⬜ (MCP 33→34)
- **W5 — Agentic Knowledge Reports FE (Glide grid + streaming + editor)** — ⬜
- **W6 — README + doc-site** — ⬜
- **GA — merge feature/v8.19 → main + tag v8.19.0** — ⬜

## Log
- 2026-06-21: plan approved (full scope locked); `feature/v8.19` created from main @ 9f543472; PROGRESS committed.
