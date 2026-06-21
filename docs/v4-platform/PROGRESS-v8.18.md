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

- **W1 — v8.16/v8.17 follow-ups** — ✅
  - W1.1 cost-meter SERVER-cost E2E — ✅ `frontend/e2e/chat-server-cost.spec.ts` (PR #331).
  - W1.2 laravel/ai 0.7 bump — ✅ **DEFERRED + GUARDED** (PR #331): `tests/Unit/Ai/LaravelAiPinTest.php` locks
    `laravel/ai` to 0.6 while `padosoft/laravel-ai-regolo` constrains `^0.6` (fails the moment regolo allows ^0.7).
  - W1.3 FinOps money fixed-precision — ✅ package `laravel-ai-finops` **v1.3.0** released (additive 8-dp
    decimal-STRING money, `*_decimal`); host bumped to `^1.3` + guard tests (PR #333).
- **W2 — eval-harness retrieval-metric delegation** — ✅ (PR #332): `PackageMetricAdapter` (sole anti-corruption
  importer) delegates MRR/nDCG@k to `padosoft/eval-harness` v1.3.0 (`2^grade-1` gain preserved, golden-equal),
  adds answer-containment@k; eval-harness moved require-dev → **require `^1.3.0`** (now runtime).
- **W3 — configurable chunk overlap** — ✅ (PR #334): `KB_CHUNK_OVERLAP_TOKENS` wired into `MarkdownChunker`
  (paragraph-bounded tail carry; 0=off; default 64; re-ingest required) + docs (R6/R9) + doc-site note.
- **W4 — gamification** — ✅ (PR #335)
  - W4.1 `KB_GAMIFICATION_ENABLED` default-ON — ✅ (R43 both states).
  - W4.2 AI gamification insights (user + project + tenant, full) — ✅ tri-surface (R44):
    `GamificationQualityMetricsService` + `GamificationNarratorService` + `GamificationInsightsService` +
    `kb_gamification_insights` + `gamification:narrate` + `/api/me/coaching` + `/api/admin/engagement/insights` +
    super-admin `regenerate` + `KbGamificationInsightsTool` (MCP 31→32) + `CoachingCard` + `GamificationInsightsPanel`.
- **W5 — README + doc-site refresh** — ✅ (PR W5): roadmap row `v8.18.0 ✅ shipped 2026-06-21` + changelog + MCP
  31→32 + sister-packages (eval-harness v1.3 runtime require, laravel-ai-finops v1.3) + feature/moat rows +
  doc-site (retrieval-pipeline.mdx eval-harness delegation note, ai-finops.mdx decimal-money note;
  gamification.mdx AI coaching deep section shipped in W4).
- **GA — merge feature/v8.18 → main + tag v8.18.0** — 🟡 in progress.

## RC tags (R39)
- Per-wave RC tags were NOT cut individually this cycle (waves merged directly into the integration branch under
  the compressed schedule). The final `v8.18.0` GA tag fires at the `main` merge (R37, --merge) per the user's
  "tag + release v8.18.0 at the end of the 5 tasks" directive.

## Log
- 2026-06-20: plan approved; `feature/v8.18` created; PROGRESS committed.
- 2026-06-20→21: all five waves shipped via PRs #331 (W1.1/W1.2), #333 (W1.3 host) + `laravel-ai-finops v1.3.0`,
  #332 (W2), #334 (W3), #335 (W4). Deep R36 Copilot loops (#332 ×10 rounds, #335 ×9 + 4 Copilot-autofix commits;
  notable real fixes: nDCG `2^grade-1` gain transform, Postgres-safe `frontmatter_json` whereNotNull, R3 subquery
  rewrite, atomic upsert R21, the super-admin E2E reseed-wipes-session fix, AI-off on the CI server step).
- 2026-06-21: W5 docs refresh; GA pending (merge → main + tag v8.18.0 + Release).
