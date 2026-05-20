# STATUS — 2026-05-20 — v8.0 auto-mode runtime

Questo file è il checkpoint operativo del loop auto-mode v8.0.
Non sostituisce roadmap/changelog: traccia solo stato runtime
(PR aperte, head sha, CI/review, prossimo step operativo).

Regola fissa di aggiornamento:
- Dopo ogni step significativo, aggiornare la sezione
  `AUTO-MODE CHECKPOINT`.
- Usare sempre timestamp UTC e riferimenti assoluti (PR, SHA, URL).

## AUTO-MODE CHECKPOINT

- updated_at_utc: 2026-05-20T11:16:18Z
- goal: 100% roadmap completion
- base_branch: feature/v8.0
- open_pr_count: 1
- agent_state: working
- last_action: automode poll snapshot + prompt render
- next_action: if child process exited -> dispatch immediately; else keep monitoring

- prs:
  - #206 head=8896c71 state=OPEN merge=UNSTABLE review= inline_on_head=0
    - url: https://github.com/lopadova/AskMyDocs/pull/206
    - checks: RAG regression gate (ci):COMPLETED:SUCCESS; PHPUnit (PHP 8.3):COMPLETED:SUCCESS; PHPUnit (PHP 8.3):COMPLETED:SUCCESS; PHPUnit (PHP 8.4):COMPLETED:SUCCESS; PHPUnit (PHP 8.4):COMPLETED:SUCCESS; PHPUnit (PHP 8.5):IN_PROGRESS:; PHPUnit (PHP 8.5):COMPLETED:SUCCESS; Vitest:COMPLETED:SUCCESS; Vitest:COMPLETED:SUCCESS; Playwright E2E:IN_PROGRESS:
