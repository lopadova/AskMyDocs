# STATUS — 2026-05-20 — v8.0 auto-mode runtime

Questo file è il checkpoint operativo del loop auto-mode v8.0.
Non sostituisce roadmap/changelog: traccia solo stato runtime
(PR aperte, head sha, CI/review, prossimo step operativo).

Regola fissa di aggiornamento:
- Dopo ogni step significativo, aggiornare la sezione
  `AUTO-MODE CHECKPOINT`.
- Usare sempre timestamp UTC e riferimenti assoluti (PR, SHA, URL).

## AUTO-MODE CHECKPOINT

- updated_at_utc: 2026-05-21T00:22:50Z
- goal: 100% roadmap completion
- base_branch: feature/v8.0
- open_pr_count: 0
- agent_state: w6_2_ready_to_pr
- last_action: implemented W6.2 chat collection picker and backend `filters.collection_id` scope (chat request + message + stream + retrieval filters + `/api/kb/collections`) and verified tests on 2026-05-21 (PHPUnit 14 tests, Vitest 5 tests)
- next_action: commit W6.2 branch changes, open PR, run CI/review loop, and merge when green with no must-fix findings

- prs:
  - #208: https://github.com/lopadova/AskMyDocs/pull/208 (head: feature/v8.0-W5.5-threshold-preview, sha: 3133e7ec65632a6c6ff74851b0daef9611b2ff44, status: merged, merged_at_utc: 2026-05-20T21:26:17Z)
  - #209: https://github.com/lopadova/AskMyDocs/pull/209 (head: feature/v8.0-W6.1-semantic-collections, sha: cf753a8e92ec63be3b1817d68bf42bd6a67c986c, status: merged, merged_at_utc: 2026-05-20T22:12:35Z)




