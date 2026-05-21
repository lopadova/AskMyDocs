# STATUS — 2026-05-20 — v8.0 auto-mode runtime

Questo file è il checkpoint operativo del loop auto-mode v8.0.
Non sostituisce roadmap/changelog: traccia solo stato runtime
(PR aperte, head sha, CI/review, prossimo step operativo).

Regola fissa di aggiornamento:
- Dopo ogni step significativo, aggiornare la sezione
  `AUTO-MODE CHECKPOINT`.
- Usare sempre timestamp UTC e riferimenti assoluti (PR, SHA, URL).

## AUTO-MODE CHECKPOINT

- updated_at_utc: 2026-05-21T09:19:40Z
- goal: 100% roadmap completion
- base_branch: feature/v8.0
- open_pr_count: 1
- agent_state: w87_pr222_ci_running
- last_action: Completed W8.6 docs closure on feature/v8.0 (commit 3ef68c54d7a5c4ec8bd793f1adf8ac9d9a7e8d0e), pushed tag v8.0.0-rc4, opened GA PR #222 feature/v8.0 -> main at 2026-05-21T09:19:35Z.
- next_action: wait/recheck PR #222 checks; if green and no must-fix findings, merge with --merge --delete-branch, tag v8.0.0 on merge commit, and close runtime checkpoint.
- prs:
  - #208: https://github.com/lopadova/AskMyDocs/pull/208 (head: feature/v8.0-W5.5-threshold-preview, sha: 3133e7ec65632a6c6ff74851b0daef9611b2ff44, status: merged, merged_at_utc: 2026-05-20T21:26:17Z)
  - #209: https://github.com/lopadova/AskMyDocs/pull/209 (head: feature/v8.0-W6.1-semantic-collections, sha: cf753a8e92ec63be3b1817d68bf42bd6a67c986c, status: merged, merged_at_utc: 2026-05-20T22:12:35Z)
  - #210: https://github.com/lopadova/AskMyDocs/pull/210 (head: feature/v8.0-W6.2-chat-collection-picker-r2, sha: 3300e1b0602ea2bad8f744f6a0598a094a477fc1, status: merged, merged_at_utc: 2026-05-20T22:59:45Z)
  - #211: https://github.com/lopadova/AskMyDocs/pull/211 (head: feature/v8.0-W6.3-mcp-resource-exposure, sha: 4600fdbabb0c3683d90afda903c55d200935289c, status: merged, merged_at_utc: 2026-05-20T23:36:50Z, merge_commit: aaa9c61941fd740ce45a71440f268319a32673dc)
  - #212: https://github.com/lopadova/AskMyDocs/pull/212 (head: feature/v8.0-W6.4-collection-new-member-event, sha: 40cfb4e6302a8820993b00d50c0e247503562ee6, status: merged, merged_at_utc: 2026-05-20T23:58:35Z, merge_commit: 05be93a80654a9be5a4c6a4f09e5d4a27fd67470)
  - #213: https://github.com/lopadova/AskMyDocs/pull/213 (head: feature/v8.0-W7.1-mcp-tenant-tokens, sha: 9ba67301bebcaa5c0b8099768e2b60406fa3c6c1, status: merged, merged_at_utc: 2026-05-21T00:23:29Z, merge_commit: 6c746e5b4459eb4cf11851ccf5194b9fbad4f117)
  - #214: https://github.com/lopadova/AskMyDocs/pull/214 (head: feature/v8.0-W7.2-mcp-propose-tools, sha: 29372c716785e945692d30db9616bd70d521076f, status: merged, merged_at_utc: 2026-05-21T00:44:11Z, merge_commit: a669c6965d327cfcf438c864684db05a290b32f1)
  - #215: https://github.com/lopadova/AskMyDocs/pull/215 (head: feature/v8.0-W7.3-mcp-scope-guard, sha: 46c8c5321411d3db9b36edbf0036e554e37f708b, status: merged, merged_at_utc: 2026-05-21T01:15:53Z, merge_commit: d92092ad4f60b55398fd39026876600d579e1f08)
  - #216: https://github.com/lopadova/AskMyDocs/pull/216 (head: feature/v8.0-W7.4-mcp-connect-helper, sha: 8a91c46982744ac3c7342fe010402d3dbd3210c2, status: merged, merged_at_utc: 2026-05-21T01:41:40Z, merge_commit: 7eb1430fdd4e11217f11afb7fc155c755ffdf180)
  - #217: https://github.com/lopadova/AskMyDocs/pull/217 (head: feature/v8.0-W8.1-compliance-reports-foundation, sha: 6dca2c820ac3a625577ff681700ad1c31195277a, status: merged, merged_at_utc: 2026-05-21T03:12:17Z, merge_commit: 10fef355d63ca3648db45b53394ac59af9bcc9b5)
  - #218: https://github.com/lopadova/AskMyDocs/pull/218 (head: feature/v8.0-W8.2-compliance-report-generator, sha: 3ffe4139ae2956d39749dba1def03fb4579cc975, status: merged, merged_at_utc: 2026-05-21T04:30:52Z, merge_commit: 047e249411d0fd496fbb5f05e991357985f9ef91)
  - #219: https://github.com/lopadova/AskMyDocs/pull/219 (head: feature/v8.0-W8.3-compliance-export, sha: ab90a0216cc157436fc35ad497e2d3031b11fc35, status: merged, merged_at_utc: 2026-05-21T04:49:59Z, merge_commit: 48035294b1653d9897a036c6219675ef44e00a5f)
  - #220: https://github.com/lopadova/AskMyDocs/pull/220 (head: feature/v8.0-W8.4-compliance-spa-verify, sha: 49d857ad289705bd3d585e0b0165adb4f2e8d8ac, status: merged, merged_at_utc: 2026-05-21T05:11:00Z, merge_commit: 68bc45e556485a685085eb44233a5411a5d2ca29)
  - #221: https://github.com/lopadova/AskMyDocs/pull/221 (head: feature/v8.0-W8.5-compliance-digest-quarterly, sha: 18f605e5b7e49873a67455f3f1c80dbdae8fb13d, status: merged, merged_at_utc: 2026-05-21T09:08:44Z)










  - #222: https://github.com/lopadova/AskMyDocs/pull/222 (head: feature/v8.0, sha: 3ef68c54d7a5c4ec8bd793f1adf8ac9d9a7e8d0e, status: open, opened_at_utc: 2026-05-21T09:19:35Z)