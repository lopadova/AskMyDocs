# PROGRESS — v8.18 (live tracker, update as you go)

Authoritative plan: `~/.claude/plans/squishy-marinating-cocke.md`. This file = current state for resume
across context windows.

## Status legend
⬜ not started · 🟡 in progress · ✅ done · 🔵 blocked/waiting

## Branches
- `feature/v8.18` (integration) — created from `main` @ af00b540, pushed.
- Sub-branches use the flat hyphenated form: `feature/v8.18-<name>`. PR target = `feature/v8.18` (R37).

## Local env
- PowerShell + Herd shims: `composer`, `php85`. Tests: `php85 -d memory_limit=1G vendor/bin/phpunit …`.
- Local package clones: `Ai/laravel-ai-finops` (v1.2.0), `Ai/padosoft-eval-harness` (v1.3.0).
- copilot-cli out of budget → R40 local gate is the `code-reviewer` subagent.

## Waves

- **W1 — v8.16/v8.17 follow-ups** — 🟡 (branch `feature/v8.18-W1ab-followups`)
  - W1.1 cost-meter SERVER-cost E2E — ✅ `frontend/e2e/chat-server-cost.spec.ts` (USD + non-USD; stubs only
    the external `/messages` boundary, asserts server `metadata.cost` reaches the meter).
  - W1.2 laravel/ai 0.7 bump — 🔵 **BLOCKED + GUARDED**: `padosoft/laravel-ai-regolo` pins `laravel/ai:^0.6`;
    bumping the host to `^0.7` would break Regolo. Deferred until regolo ships a `^0.7`-compatible release.
    Guard test `tests/Unit/Ai/LaravelAiPinTest.php` locks the pin (installed laravel/ai stays 0.6 while regolo
    constrains ^0.6) so the deferral can't drift silently — it fails the moment regolo allows ^0.7.
  - W1.3 FinOps money fixed-precision — ⬜ (package release on `laravel-ai-finops` first, then host bump)
- **W2 — eval-harness retrieval-metric delegation** — ⬜ (plan: `2026-06-16-askmydocs-eval-harness-delegation.md`; v1.3.0 on Packagist)
- **W3 — configurable chunk overlap** — ⬜ (plan: `2026-06-16-askmydocs-chunk-overlap.md`)
- **W4 — gamification** — ⬜
  - W4.1 `KB_GAMIFICATION_ENABLED` default-ON — ⬜
  - W4.2 AI gamification insights (user + project + tenant, full) — ⬜
- **W5 — README + doc-site refresh** — ⬜
- **GA — merge feature/v8.18 → main + tag v8.18.0** — ⬜

## RC tags (R39)
- one per wave closure: `v8.18.0-rc1` (W1), `rc2` (W2), … final `v8.18.0` GA at the main merge.

## Log
- 2026-06-20: plan approved; `feature/v8.18` created; PROGRESS committed.
