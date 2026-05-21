# STATUS — 2026-05-20 — v8.0 auto-mode runtime

Questo file è il checkpoint operativo del loop auto-mode v8.0.
Non sostituisce roadmap/changelog: traccia solo stato runtime
(PR aperte, head sha, CI/review, prossimo step operativo).

Regola fissa di aggiornamento:
- Dopo ogni step significativo, aggiornare la sezione
  `AUTO-MODE CHECKPOINT`.
- Usare sempre timestamp UTC e riferimenti assoluti (PR, SHA, URL).

## AUTO-MODE CHECKPOINT

- updated_at_utc: 2026-05-21T00:03:34Z
- goal: 100% roadmap completion
- base_branch: feature/v8.0
- open_pr_count: 0
- agent_state: w7_1_patch_ready_local
- last_action: Implemented W7.1 foundation on 2026-05-21: new mcp_tenant_tokens schema+model, admin API /api/admin/mcp/tokens (list/mint/revoke), SPA route /app/admin/mcp/tokens and admin rail entry, with targeted tests green (McpTenantTokenControllerTest + TenantIdMandatoryTest)
- next_action: commit/push W7.1 patch, open PR against feature/v8.0, then continue CI/review/merge loop

- prs:
  - #208: https://github.com/lopadova/AskMyDocs/pull/208 (head: feature/v8.0-W5.5-threshold-preview, sha: 3133e7ec65632a6c6ff74851b0daef9611b2ff44, status: merged, merged_at_utc: 2026-05-20T21:26:17Z)
  - #209: https://github.com/lopadova/AskMyDocs/pull/209 (head: feature/v8.0-W6.1-semantic-collections, sha: cf753a8e92ec63be3b1817d68bf42bd6a67c986c, status: merged, merged_at_utc: 2026-05-20T22:12:35Z)
  - #210: https://github.com/lopadova/AskMyDocs/pull/210 (head: feature/v8.0-W6.2-chat-collection-picker-r2, sha: 3300e1b0602ea2bad8f744f6a0598a094a477fc1, status: merged, merged_at_utc: 2026-05-20T22:59:45Z)
  - #211: https://github.com/lopadova/AskMyDocs/pull/211 (head: feature/v8.0-W6.3-mcp-resource-exposure, sha: 4600fdbabb0c3683d90afda903c55d200935289c, status: merged, merged_at_utc: 2026-05-20T23:36:50Z, merge_commit: aaa9c61941fd740ce45a71440f268319a32673dc)
  - #212: https://github.com/lopadova/AskMyDocs/pull/212 (head: feature/v8.0-W6.4-collection-new-member-event, sha: 40cfb4e6302a8820993b00d50c0e247503562ee6, status: merged, merged_at_utc: 2026-05-20T23:58:35Z, merge_commit: 05be93a80654a9be5a4c6a4f09e5d4a27fd67470)




