# v4.0 Week 2 closure — 2026-04-30

W2 deliverable per `project_v40_week_sequence`: **`padosoft/laravel-ai-regolo` v0.1 + AskMyDocs adopta `laravel/ai` SDK**.

## Sub-tasks shipped

| Sub-task | PR | Merge SHA on `feature/v4.0` | Outcome |
|---|---|---|---|
| W2.A.0 — rename `padosoft/agent-llm` → `padosoft/laravel-ai-regolo` | #81 | `e4f7308` | Standalone repo published to Packagist (v0.2.x line) |
| W2.B prep — `laravel/ai` SDK foundation + R36 9-step flow | #83 | `349080f` | Runtime deps committed; e2e helpers (`resetDb`/`seedDb`/`resetAndSeed`/`loginAsProjectUser`); CI workflow re-architected (key:generate + sed `set_env` + cold-start CLI migrate); R36 + R37 + R38 codified in CLAUDE.md and skills |
| W2.B refactor — RegoloProvider delegates to `laravel/ai` SDK | #84 | `33fef2a` | `RegoloProvider` rewritten to use `laravel/ai` Anthropic-style messages + `padosoft/laravel-ai-regolo` v0.2 driver; chat-history validation tightened; multi-step finishReason fix; PHP 8.3/8.4/8.5 matrix green |

## Acceptance gates passed

- 10/10 CI checks GREEN on both PRs at merge time (6 PHPUnit × 8.3/8.4/8.5, 2 Vitest, 2 Playwright E2E)
- Copilot Code Review converged to **0 outstanding must-fix** on both PRs after the R36 loop (PR #83 took ~11 review cycles, PR #84 ~7)
- All architecture tests still pass (R30/R31 tenant isolation, R32 memory privacy, R34/R35 KB/canonical invariants)
- E2E flake on `chat.spec.ts:22` (Test timeout 20 s exceeded inside auto-fixture + SPA boot) closed by wrapping the test in a nested `test.describe.configure({ timeout: 60_000 })` block — covers fixtures, unlike `testInfo.setTimeout()` inside the body which only covers the body itself
- New Padosoft package (`padosoft/laravel-ai-regolo`) shipped with the standalone testsuite + `tests/Live/` opt-in pattern + AI vibe-coding pack section in README

## Lessons captured (added during W2)

- **R36 step 8** — `gh pr edit <N> --add-reviewer copilot-pull-request-reviewer` is mandatory after every push; without it Copilot does not auto-re-review and the loop stalls indefinitely (Lorenzo flagged a 90 min wait incident on PR #84 commit `3c0158c`)
- **R38** — heavy work belongs in CLI workflow steps, not behind `php artisan serve`. The structural fix that landed on PR #85 (and the matching changes here on PR #83) is the canonical example: move `migrate:fresh` to a CLI step before Playwright starts; sets `E2E_SKIP_HTTP_RESET=1` so setup helpers skip the redundant boot reset
- **R37** — `feature/v4.x` integration branch + sub-branches per sottotask; `feature/v4.0` accumulates W1..W8 work; merge to `main` happens once at v4.0 RC
- **Auto-merge convention** (memory `feedback_auto_merge_when_ready`) — Claude merges PRs himself when R36 step-9 conditions are met, no longer waits for Lorenzo's manual click

## Next: W3 (Vercel AI SDK UI full migration of chat)

Per `feedback_vercel_chat_migration_design_fidelity`: design fidelity 1:1, design doc completo prima del codice. W3 sub-branch and design doc to be drafted next.
