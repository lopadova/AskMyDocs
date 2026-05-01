# v4.0 Week 3 closure — 2026-05-01

W3 deliverable per `project_v40_week_sequence`: **Vercel AI SDK UI full migration of chat (design fidelity assoluta).**

The W3 design doc — `docs/v4-platform/PLAN-W3-vercel-chat-migration.md` — locked five architectural decisions ahead of execution: Option B streaming endpoint, 3-PR breakdown (W3.1 backend / W3.2 frontend / W3.3 cleanup), 15 representative visual states under `toHaveScreenshot`, ±5% INP/TTI/CLS tolerance vs pre-migration baseline, and a 1.5-week stretch (W4 slips ~3 days). All five held through delivery.

## Sub-tasks shipped

| Sub-task | PR | Merge SHA on `feature/v4.0` | Outcome |
|---|---|---|---|
| W3.0 — design doc + W2 closure | #86 | `0db114e` | Design doc landed; five decision points (§4.4/§7/§8) ratified; sub-branch reservation for W3.1 / W3.2 / W3.3 |
| W3.1 — backend SSE streaming endpoint | #87 | `8aa8cee` | `POST /conversations/{id}/messages/stream` live; per-provider streaming adapter with single-chunk fallback; PHPUnit `MessageStreamControllerTest` 8 scenarios green; recorded SSE fixture for byte-for-byte protocol-drift assertion |
| W3.2 — Vercel AI SDK chat foundation | #88 | `948ef9c` | `useChatStream()` hook + transport + message-shape adapters + scaffolded test surface; `mapStatusToDataState()` helper landed with the unit test from `PLAN-W3` §7.1; existing `chat*.spec.ts` untouched |
| W3.2 — atomic chat swap onto `useChatStream()` | #89 | `dd8ca5c` | `ChatView` + `MessageThread` + `Composer` + `MessageBubble` migrated in a single atomic commit; `use-chat-mutation.ts` deleted; 60+ testids preserved; pixel-level `toHaveScreenshot` snapshots committed across 15 representative states |
| W3.3 — BE wire format catch-up | #90 | `ee82ef9` | `StreamChunk` + `FallbackStreaming` + `MessageStreamController` realigned to SDK v6 `UIMessageChunk` shape (`start` / `text-start` / `text-delta(id, delta)` / `text-end` / `source-url`; `data-confidence` + `data-refusal` nested under `data:{}`; `finish` constrained to the SDK union via `normalizeFinishReason()`) |

## Acceptance gates passed

- 10/10 CI checks GREEN on every PR at merge time (6 PHPUnit × 8.3/8.4/8.5, 2 Vitest, 2 Playwright E2E)
- Copilot Code Review converged to **0 outstanding must-fix** on every PR after the R36 loop:
  - PR #87 (W3.1): ~4 review cycles
  - PR #88 (W3.2 foundation): **9 cycles**
  - PR #89 (W3.2 atomic swap): **13 cycles** — the highest single-PR cycle count in v4.0 to date, driven by the swap surface (4 components + adapter layer + 15 visual snapshots all interacting)
  - PR #90 (W3.3 wire format): **3 cycles**
- Pre-migration baseline (Lighthouse `/app/chat`, Vitest snapshots, Playwright HTML report) recorded under `tests/baseline/W3-pre-migration/` before W3.2 started; post-migration INP / TTI / CLS within ±5% on every measurement
- All architecture tests still pass (R30/R31 tenant isolation, R32 memory privacy, R34/R35 KB / canonical invariants)
- The four pre-existing chat suites — `chat.spec.ts`, `chat-filters.spec.ts`, `chat-mention.spec.ts`, `chat-refusal.spec.ts` — stayed BYTE-IDENTICAL post-swap (helper-extraction commit `frontend/e2e/helpers/stub-chat.ts` from W3.2 foundation absorbed every wire change in one place; `git diff --stat origin/feature/v4.0...HEAD -- <four files>` showed 0 lines)
- Six new `chat-stream*.spec.ts` files added per `PLAN-W3` §7.3, covering streaming UX, refusal as `data-refusal` part, citation `source` parts arriving before text, filters / mentions / presets round-trip during streaming

## Lessons captured (added during W3)

- **Subagent type matters** — `feature-dev:code-architect` is design-only (no Write/Edit/Bash tools); for "actually build + commit" sub-agents use `general-purpose`. Discovered when the W3.2 atomic-swap fan-out failed silently because the architect agent emitted a plan without touching files. Codified in memory `feedback_subagent_type_choice`.
- **JSDoc `*/` inside backtick code-spans terminates the comment** — quoting `**/*.ts` or `[^"]*/messages` inside a `/** ... */` block blows up CI Playwright + esbuild parse. Pre-flight `grep '\\*/' frontend/src/**/*.{ts,tsx}` before push avoids the round-trip. Codified in memory `feedback_jsdoc_star_slash_in_backticks`.
- **R36 cycles cluster around swap surface** — PR #89 hit 13 cycles because the atomic swap touched four components plus the adapter layer plus 15 visual snapshots simultaneously. Future high-fan-out swaps should bundle the foundation PR earlier (PR #88 pattern) so the swap PR has only the orchestration concern, not the type-shape concern.
- **Wire format catch-up is non-optional after SDK foundation** — PR #88 + #89 deferred the BE alignment ("the SDK accepts current shape via custom transport"); PR #90 closed the loop in 3 cycles. Lesson: when the FE adopts an SDK primitive that has a published wire contract, schedule the BE catch-up in the SAME week, not as cleanup.
- **Pixel-level snapshots ARE the design-fidelity contract** — `toHaveScreenshot({ maxDiffPixels: 0 })` on 15 states (per `PLAN-W3` §7.4) caught two regressions during the R36 loop on PR #89 that the testid + ARIA assertions had let through. Recommendation for any future "preserve UX bit-for-bit" migration: snapshots are mandatory, not nice-to-have.
- **Auto-merge convention held throughout** (memory `feedback_auto_merge_when_ready`) — every W3 PR was merged by Claude when the R36 step-9 conditions were met, no Lorenzo manual click. Zero false positives on the gate.

## Residual cleanup parked for W3.4 (optional, low risk)

Three cosmetic items are tracked for an optional W3.4 cleanup PR but were intentionally kept out of W3.3 to avoid scope creep on the wire-format fix:

- `frontend/src/features/chat/message-shape-adapters.ts::getCitations` — drop the dual `'source'` / `'source-url'` discriminator and keep only `'source-url'` (the BE now emits the latter exclusively after PR #90)
- `frontend/e2e/helpers/stub-chat.ts` — drop the `NOTE: stub vs BE shape divergence` block (stub and BE now emit byte-identical frames)
- Stale comments in `message-shape-adapters.ts` referencing `StreamChunk::source(...)` / `StreamChunk::TYPE_SOURCE` — neither symbol exists post-PR #90

These are zero-functional-change diffs. They will land as a single trivial PR if Lorenzo wants the polish, or stay parked indefinitely with no impact.

## Production impact

The chat feature now streams end-to-end on `feature/v4.0`. The SDK consumes `UIMessageChunk` frames emitted directly by `MessageStreamController`; first-token latency dropped from ~2.8 s (synchronous JSON wait) to ~400 ms (first SSE chunk) on the Lighthouse baseline. The legacy synchronous `MessageController` POST endpoint stays in the codebase as a backward-compat fallback exercised by `MessageControllerTest` and is no longer hit by the FE.

## Next: W4 — `padosoft/laravel-patent-box-tracker` v0.1

Per `project_v40_week_sequence` and `project_patent_box_auto_tracker_v40`: a standalone Laravel package that classifies R&D activity across the AskMyDocs + sister Padosoft repositories and emits an audit-grade PDF + JSON dossier suitable for the Italian Patent Box (110% R&D deduction) regime. Detailed design doc: `docs/v4-platform/PLAN-W4-patent-box-tracker.md` (this PR).
