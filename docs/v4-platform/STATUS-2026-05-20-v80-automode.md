# STATUS — 2026-05-20 — v8.0 auto-mode runtime

Questo file è il checkpoint operativo del loop auto-mode v8.0.
Non sostituisce roadmap/changelog: traccia solo stato runtime
(PR aperte, head sha, CI/review, prossimo step operativo).

Regola fissa di aggiornamento:
- Dopo ogni step significativo, aggiornare la sezione
  `AUTO-MODE CHECKPOINT`.
- Usare sempre timestamp UTC e riferimenti assoluti (PR, SHA, URL).

## AUTO-MODE CHECKPOINT

- updated_at_utc: 2026-05-20T23:14:32Z
- goal: 100% roadmap completion
- base_branch: feature/v8.0
- open_pr_count: 1
- agent_state: patching_ci_failures_pr_211
- last_action: CI on PR #211 failed on 2026-05-20 with "Premature end of PHP process" in PHPUnit matrix while running Tests\Feature\V8\McpCollectionResourcesTest; patched test compatibility by using positional constructors for JsonRpcRequest/ServerContext and re-verified locally (2 passed)
- next_action: commit/push CI-hardening patch to PR #211, rerun checks, then continue review/merge loop

- prs:
  - #208: https://github.com/lopadova/AskMyDocs/pull/208 (head: feature/v8.0-W5.5-threshold-preview, sha: 3133e7ec65632a6c6ff74851b0daef9611b2ff44, status: merged, merged_at_utc: 2026-05-20T21:26:17Z)
  - #209: https://github.com/lopadova/AskMyDocs/pull/209 (head: feature/v8.0-W6.1-semantic-collections, sha: cf753a8e92ec63be3b1817d68bf42bd6a67c986c, status: merged, merged_at_utc: 2026-05-20T22:12:35Z)
  - #210: https://github.com/lopadova/AskMyDocs/pull/210 (head: feature/v8.0-W6.2-chat-collection-picker-r2, sha: 3300e1b0602ea2bad8f744f6a0598a094a477fc1, status: merged, merged_at_utc: 2026-05-20T22:59:45Z)
  - #211: https://github.com/lopadova/AskMyDocs/pull/211 (head: feature/v8.0-W6.3-mcp-resource-exposure, sha: ab506ee512a23f30a86663129e5b45ac28f04c96, status: open, checks: failed_then_patching, review: pending)




