# STATUS — 2026-05-20 — v8.0 auto-mode runtime

Questo file è il checkpoint operativo del loop auto-mode v8.0.
Non sostituisce roadmap/changelog: traccia solo stato runtime
(PR aperte, head sha, CI/review, prossimo step operativo).

Regola fissa di aggiornamento:
- Dopo ogni step significativo, aggiornare la sezione
  `AUTO-MODE CHECKPOINT`.
- Usare sempre timestamp UTC e riferimenti assoluti (PR, SHA, URL).

## AUTO-MODE CHECKPOINT

- updated_at_utc: 2026-05-20T21:09:04Z
- goal: 100% roadmap completion
- base_branch: feature/v8.0
- open_pr_count: 0
- agent_state: active
- last_action: implemented W5.5 threshold live preview slice on 2026-05-20 at HEAD 0c9226d0476045d46b29627dcb87253b7be695dd (new POST /api/admin/kb/collections/preview + admin collections preview count UI + tests green: 5 tests, 19 assertions)
- next_action: commit/push W5.5 preview changes to feature/v8.0-W5.5-threshold-preview, open PR, then run CI/review loop

- prs:
  - none



