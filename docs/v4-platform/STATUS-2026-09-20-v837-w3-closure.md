# STATUS — AskMyDocs v8.37.0 GA — Document Intelligence W3 (Digitization Review)

**Cycle:** v8.37 (Digitization Review — third of five Document Intelligence workstreams;
W4/W5 continue as v8.38/v8.39, each its own release per the per-Wn versioning established
at v8.36 closure).
**Closed:** 2026-09-20. **GA tag:** `v8.37.0` (merge `feature/v8.37 → main`, R37 per-release).
**Origin:** continuation of the [Annota AI gap audit](AUDIT-2026-09-11-annota-ai-gap.md) §2.1
("Side-by-side correction of extracted text", "Per-asset review status") that opened the
v8.36 cycle. W1 (ADR 0029) lets AskMyDocs ingest a scan; W2 (ADR 0030) keeps the converted
Markdown as a diffable artifact; neither gave a human a reason, or a place, to look at what
the engine produced before it grounds an answer. Plan:
[`PLAN-v8.36-document-intelligence-and-llm-wiki-export.md`](PLAN-v8.36-document-intelligence-and-llm-wiki-export.md)
§W3. Design: [ADR 0031](../adr/0031-v837-digitization-review-and-auto-tier-mapping.md).

## Sub-tasks (merged into `feature/v8.37`, each with R40 local-critic + R36 cloud loop to 0 must-fix)

| Wn | Feature | PR | Base |
|---|---|---|---|
| W0 | ADR 0031 — Digitization Review and the auto-tier mapping | #493 | `main` |
| W3a | Schema (`kb_document_page_reviews`, `kb_text_correction_candidates`) + Reranker auto-tier firewall fix + `KbReviewService` core + PHP/CLI+HTTP+MCP-read tri-surface (ADR 0031 §2/§4/§5/§7/§8) | #494 | `feature/v8.37` |
| W3b | Correction-candidate propose/approve/reject flow, `KbProposeTextCorrectionTool` (ADR 0031 §6-7) | #496 | `feature/v8.37` |
| W3c | Frontend Review tab (`ReviewTab.tsx`), plus unrelated MCP transport hardening surfaced by review (ADR 0031 §2/§4/§6/§9) | #497 | `feature/v8.37` |
| W6 | RC + GA close (this doc + README roadmap-row flip + Changelog + GA merge + `v8.37.0` tag) | — | `feature/v8.37 → main` |

PR #494's scope grew across its own review loop: originally proposed as schema +
retrieval-ranking only, it ended up shipping the full `KbReviewService` core plus its
HTTP + MCP-read tri-surface (R44), because the read/status/approve capabilities needed
their surfaces to be reviewable at all — only the not-yet-written correction-candidate
flow stayed deferred to #496 as originally planned.

## New schema (2 new tenant-aware tables, R30/R31)

- **`kb_document_page_reviews`** — per-page review progress on a converted (OCR'd)
  document. `UNIQUE(tenant_id, knowledge_document_id, page_number)`; upserted via
  `Model::upsert()` (a single atomic `INSERT ... ON CONFLICT DO UPDATE`, not
  `updateOrCreate()`'s SELECT-then-write) so two concurrent reviewers of the same page
  cannot race into an uncaught unique-constraint violation. Cascade-deletes with its
  document. Postgres additionally `CHECK`s `page_number >= 1` (SQLite can't `ALTER TABLE
  ADD` a CHECK post-creation, so `KbReviewService::markPageReviewed()`'s application-layer
  guard is the only enforcement under the test driver).
- **`kb_text_correction_candidates`** — agent-proposed OCR text-correction candidates
  (ADR 0003's `/suggest → /candidates → /promote` pattern, restated for a probable
  transcription error). `idempotency_key` is `sha256` of the concatenation of the
  **per-field** `sha256` digests of `(tenant, user, document, version_hash, page, old,
  new)` — hashing each field first keeps the key injective; a plain concatenation of raw
  fields is not (`old_text="ab", new_text="c"` would collide with `old_text="a",
  new_text="bc"`). `UNIQUE(idempotency_key)` is the replay/dedup mechanism. `status`
  transitions `pending → applying → applied | rejected` only under `lockForUpdate()`
  transactions (R21).

Both migrations are mirrored verbatim into `tests/database/migrations/` (SQLite test-schema
convention). Both models are registered in `TenantIdMandatoryTest` (R31) and
`TenantReadScopeTest` (R30). Neither table is referenced by `kb_canonical_audit` — by
design, the audit trail never depends on a table that might be gone; the correction's
audit row carries the candidate id in `metadata_json` instead.

## New events / commands / endpoints (tri-surface, R44)

All surfaces adapt one core, `KbReviewService`, tenant-scoped through
`KnowledgeDocument::forTenant()` (R30).

| Capability | PHP / CLI | HTTP | MCP |
|---|---|---|---|
| Mark a page reviewed / set status | `kb:review {document} --page={n} --status=` | `PATCH …/documents/{id}/pages/{n}/review-status` | — (documented R44 exception, ADR 0031 §8) |
| Approve a document (`auto → human`) | `KbReviewService::approve()` | `POST …/documents/{id}/review-approve` | — (write; human-only by design) |
| Save a correction (re-ingest) | `DocumentIngestor::ingestMarkdown()` (existing, reused) | `PATCH …/documents/{id}/pages/{n}` | — (write; human-only, the review UI's Save) |
| Propose a text correction candidate | `KbReviewService::proposeCorrection()` | — (propose is MCP-only, no HTTP endpoint) | `KbProposeTextCorrectionTool` (propose only) |
| Approve / reject a correction candidate | `KbReviewService::approveCorrection()` / `rejectCorrection()` | `POST …/corrections/{id}/approve` / `POST …/corrections/{id}/reject` | — (approve/reject are HTTP-only, the documented split IS the ADR 0003 boundary) |
| Read review status | `kb:review {document} --page` (report mode) | `GET …/documents/{id}/pages/{n}` | `KbReviewStatusTool` (read) |
| Reconcile stuck `applying` corrections | `kb:review-reconcile-stuck-corrections` (documented single-surface R44 exception, scheduler-only maintenance sweep) | — | — |

Frontend (W3c): `ReviewTab.tsx` — per-page Prev/Next navigation with reviewed/unreviewed
toggle, Document Approve (independent of page-review progress by design), the
correction-candidate queue with Approve/Reject (409 `already_consumed` read as a genuine
decided outcome via axios `validateStatus`, not a network error). Degrades to a clean
"disabled" panel (never a raw error) when the flag is off (R43).

## Defaults / cost posture

- `KB_DIGITIZATION_REVIEW_ENABLED` default **OFF** (R43, both states tested). Gates the
  review UI's routes/screen (clean 404) and the HTTP review endpoints. Does **not** gate
  MCP tool *registration* — `KbProposeTextCorrectionTool` and `KbReviewStatusTool` stay
  registered with the flag off and answer `{disabled: true, flag: 'KB_DIGITIZATION_REVIEW_ENABLED'}`
  rather than a 500 or a silent success (`KnowledgeBaseServerRegistrationTest` derives the
  tool roster from the files present in `app/Mcp/Tools/`, so a config-dependent roster
  would desync from the file list the moment the flag flips).
- `KB_REVIEW_CANDIDATES_PER_HOUR` (default 60) — per-tenant rate limit on proposals,
  checked **after** the idempotency-key lookup so a replay never spends the budget.
- `KB_REVIEW_STUCK_APPLYING_MINUTES` (default 15) — window before a correction stuck in
  the intermediate `applying` state (process killed between the phase-1 commit and
  phase-2 re-embed) becomes eligible for `reconcileStuckCorrections()`.
- `KB_REVIEW_CORRECTIONS_PAGE_SIZE` — pagination size for the pending-corrections queue.

## Reranker firewall fix (ADR 0031 §5)

`Reranker::canonicalAdjustment()` returned a zero delta for **every non-canonical chunk**
before ever reading `generation_source`, so the "human > auto > raw" anti-hallucination
firewall (ADR 0014) only ever applied to canonical rows — an unreviewed OCR'd scan and a
human-reviewed one ranked identically as long as neither was canonical, which is exactly
the case Digitization Review's approval flow needs to change retrieval-time. Fixed by
moving the auto-tier penalty computation ahead of the `is_canonical` early-return, scoped
to canonical rows **or** rows whose `ocr_origin` flag is true (derived in
`KbSearchService`'s chunk-mapping from `metadata.converter.provenance === 'ocr'`) — not to
every non-canonical `generation_source = 'auto'` row, because `AutoWikiCompiler::apply()`
also writes `auto` on non-canonical rows for a ranking behaviour that predates this ADR
and must not be silently re-ordered. Two regression tests: a reviewed non-canonical scan
now strictly outranks an unreviewed one at equal similarity; two non-canonical
`human`-default rows still tie (proves the fix is a no-op for content never routed through
OCR).

## Concurrency hardening (R21)

- `KbReviewService::approve()` runs inside one transaction that `lockForUpdate()`s the
  document row and re-reads canonicity/`generation_source` from the **locked** row, never
  from the caller's possibly-stale in-memory instance — serializing concurrent `approve()`
  calls instead of racing to a duplicate audit row.
- `approveCorrection()` is a three-phase flow: (1) one transaction locks the candidate row
  together with the document row, re-validates `old_text` still occurs exactly once on the
  CURRENT (possibly newer) version before applying, and atomically marks the candidate the
  intermediate `applying` state; (2) outside any ambient transaction, mints a genuinely new
  version through `DocumentIngestor::reembedFromMarkdown()`; (3) writes the audit row. Two
  reviewers approving the same candidate concurrently produce exactly one correction
  version and one audit row — the second transaction's `lockForUpdate()` sees `status !=
  'pending'` and the caller receives 409 `already_consumed`, never a duplicate version.
- `reconcileStuckCorrections()` resolves a candidate left in `applying` (process killed
  mid-flow, since this runs synchronously in-request, not as a queued job): a matching
  `kb_canonical_audit` row proves the correction genuinely committed → finalize to
  `applied`; no matching row → revert to `pending` so a reviewer can simply retry.

## Additional scope surfaced by review: MCP transport security hardening

Copilot's review of PR #497 caught and drove fixes to four pre-existing, unrelated-to-W3
issues on the inbound MCP transport (`/mcp/kb`), landed as part of that PR's review loop
rather than split out (each fix built on the last within the same file/route):

1. `throttle:mcp` now runs before `mcp.scope` on the route — previously a request rejected
   by `EnforceMcpScope` (missing/invalid/expired/revoked token, tenant mismatch) skipped
   rate limiting entirely, allowing unthrottled token-guessing.
2. The `mcp` rate limiter is now keyed by bearer-token hash **plus** a second, independent
   IP-based limit (`MCP_SERVER_RATE_LIMIT_IP_PER_MINUTE`, default 300/min) — was keyed on
   token hash alone, bypassable by varying the token value per request.
3. `EnforceMcpScope` now validates the bearer token for every protocol method, not just
   `tools/call` — previously `initialize`/`tools/list`/etc. could reach the MCP handler
   unauthenticated once `auth:sanctum` was removed from the route.
4. `EnforceMcpScope` restores the pre-request `TenantContext` value in a `finally` block on
   every return path, including early-return scope-denial branches — it previously set the
   token's tenant but never restored it, which could leak a tenant into a subsequent
   request handled by the same long-running worker (Octane).

## E2E CI flake fixed during closure (unrelated to this cycle's feature)

PR #497's CI run surfaced a genuinely pre-existing, unrelated flaky assertion in
`admin-time-machine.spec.ts` (v8.7/W5 Cloud Time Machine — not touched by W3): a locator
assertion used the default 5s Playwright expect timeout while every comparable assertion
in the same test already used an explicit 15s timeout, so the second version row
occasionally rendered just past 5s under CI's 4-way parallel shard matrix. Aligned the
timeout to match the test's own established pattern (`frontend/e2e/admin-time-machine.spec.ts`).
Verified locally with `--workers=1` (matching CI's serial mode) against a freshly migrated
`askmydocs_test` DB — all 7 tests in the file pass, including the previously-flaky one.

## Deferred (documented, per ADR 0031 §3/§9/§10)

- **Side-by-side original ↔ Markdown pane + confidence heat-map** (ADR 0031 §3). The
  shipped `ReviewTab` covers per-page reviewed/unreviewed toggle with Prev/Next
  navigation, document Approve, and the correction-candidate queue — all wired to the
  real HTTP surfaces. It does **not** yet render the original source (PDF.js for
  `application/pdf`, `<img>` for the OCR image MIMEs) alongside the Markdown, nor the
  `ocr_confidence`-driven heat-map ADR 0031 §3 specifies. `KB_REVIEW_LOW_CONFIDENCE_THRESHOLD`
  (default 0.70) exists in config for this UI but has no consumer yet.
- **`CharacterErrorRateMetric` / `WordErrorRateMetric`** — two host-side `padosoft/eval-harness`
  metrics computing CER/WER against the human-approved version as gold, plus an
  `eval:nightly` `ocr` lane. Not started this cycle.
- **`laravel-flow` workflow integration** — the review queue as a `laravel-flow`
  definition with an approval node, worked through `flow-admin`. Not started this cycle.
- **Frontend E2E depth**: per-page navigation against a page-counted document, and the
  corrections approve/reject flow *with* seeded pending candidates — both need a dedicated
  fixture/seeder `admin-kb-review.spec.ts` does not add. Covered instead at the Vitest
  level (`ReviewTab.test.tsx`, 16 scenarios), where every `mutate()` call for every action
  is driven with a mocked hook and asserted on its exact arguments.
- **R45 doc-site deep page** — `docs-site/digitization-review.mdx` was extended (schema,
  tri-surface table, Mermaid diagram, worked example, gotchas) in PR #496 rather than
  written fresh; not a gap, but noted since the v8.36 template calls out doc-site status
  explicitly.

## Pre-existing issue observed (not introduced this cycle)

The MCP transport security gaps described above (throttle ordering, rate-limit keying,
per-method token validation, `TenantContext` restore) predate this cycle — the "Additional
scope" section above is the record of them being found and closed, not newly introduced.

## Tags — blocked by tooling, operator follow-up required

The automation session that drove this closure has `git push` access sufficient for branch
commits and PR merges, but a **`git push` of a tag ref returns `403`** (confirmed
reproducible, not a transient network error) — the underlying credential appears scoped to
`refs/heads/*` only. Neither `v8.36.0` nor `v8.37.0` is tagged as a result, even though both
cycles' code is fully merged (v8.36 → `main` via PR #492; v8.37 → `feature/v8.37`, pending
its own GA merge to `main`). An operator with a tag-capable credential should run, once the
v8.37 → `main` GA merge (below) lands:

```bash
# v8.36.0 — already on main at the PR #492 merge commit
git tag -a v8.36.0 62dd0b25 -m "v8.36.0 — Document intelligence W1+W2 (OCR ingestion, conversion artifacts). See docs/v4-platform/STATUS-2026-09-16-v836-w1-w2-closure.md."
git push origin v8.36.0

# v8.37.0 — at the GA merge commit on main (fill in the actual SHA once merged)
git tag -a v8.37.0 <GA-merge-sha-on-main> -m "v8.37.0 — Digitization Review (W3). See docs/v4-platform/STATUS-2026-09-20-v837-w3-closure.md."
git push origin v8.37.0
```

Per R39, an rc tag would normally precede the GA tag; given both are already blocked and the
code is stable (full CI green, Copilot review clean, merged), this closure skips straight to
proposing the GA tag rather than adding a redundant rc step an operator would have to push
twice under the same blocked credential.

Cycle plan: [`PLAN-v8.36-document-intelligence-and-llm-wiki-export.md`](PLAN-v8.36-document-intelligence-and-llm-wiki-export.md) §W3.
Design: [ADR 0031](../adr/0031-v837-digitization-review-and-auto-tier-mapping.md).
