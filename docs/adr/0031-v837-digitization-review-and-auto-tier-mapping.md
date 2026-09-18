# ADR 0031 — Digitization Review and the auto-tier mapping

- **Status:** Accepted
- **Date:** 2026-09-16
- **Cycle:** v8.37 (W3 of the Document Intelligence cycle)
- **Builds on:** [ADR 0003](0003-promotion-pipeline.md) (agent proposes, a
  person commits), [ADR 0014](0014-v811-auto-wiki-tier.md) (`human > auto > raw`,
  the `generation_source` column and the reranker firewall),
  [ADR 0028](0028-source-acl-mirroring-and-ingest-provenance.md)
  (`provenance_tier` — authorship, an orthogonal axis to this ADR),
  [ADR 0029](0029-v836-ocr-converter-drivers-and-ocr-provenance.md) (OCR
  drivers and provenance; this ADR extends it with the propose-only MCP
  decision), [ADR 0030](0030-v836-conversion-artifacts-on-the-time-machine.md)
  (the stored conversion artifact this workstream reviews and corrects),
  R21 atomic invariants, R30/R31 tenant scoping, R32 authorization matrix,
  R43 both-state flags, R44 tri-surface, SEC-AI-ACT-001 (mutating AI/MCP
  actions), SEC-LLM-001 (tool authorization before data access).
- **Plan:** [PLAN v8.36 → v8.40](../v4-platform/PLAN-v8.36-document-intelligence-and-llm-wiki-export.md)
  §W3; audit [AUDIT-2026-09-11-annota-ai-gap](../v4-platform/AUDIT-2026-09-11-annota-ai-gap.md) §2.1
  rows "Side-by-side correction of extracted text" and "Per-asset review status".

## Context

W1 (ADR 0029) lets AskMyDocs ingest a scan; W2 (ADR 0030) lets it keep the
converted Markdown as a stored, diffable artifact. Neither gives a human a
reason, or a place, to look at what the engine produced before it grounds an
answer. A competitor's review screen — original left, extracted text right,
per-page confidence, a correction that becomes the new gold — is table
stakes for anyone shipping OCR, and three properties of the existing schema
mean AskMyDocs does not need a new tier system to match it, only to wire the
one it already has to the one output OCR produces that other systems don't:

1. **The tier already exists, unused for this purpose.** `generation_source`
   (ADR 0014) distinguishes human-vouched content from machine output; the
   reranker firewall already ranks `human` above `auto` above raw
   (non-canonical) text. A converted page is machine output. It belongs in
   `auto` until a person looks at it — that is a fact the schema can state
   today, not a new column.
2. **The firewall has a narrow gap for exactly this case.** `Reranker::
   canonicalAdjustment()` (`app/Services/Kb/Reranker.php:266`) returns a zero
   delta before it ever reads `generation_source`, for any row where
   `is_canonical` is false. An OCR'd scan is essentially always
   non-canonical (no author writes YAML frontmatter for a paper contract),
   so the `human > auto` ordering the firewall promises is, today, a promise
   the reranker keeps only for canonical rows. A reviewed scan and an
   unreviewed one currently rank identically.
3. **The version model already has a "new correction" shape.** A correction
   is not a special edit path: it is an ordinary re-ingest through
   `DocumentIngestor`, the same one-core path every other write to
   `knowledge_documents` takes (ADR 0030 §1). W3 adds nothing here except the
   UI that produces the new Markdown and the audit trail around *why* it
   changed.

What W3 must add, precisely, is: a place to see and correct a converted page
(the UI), a durable record of what has been looked at (per-page review
status), a single, branch-aware transition from `auto` to `human` (approval),
an agent surface that can point at a probable error but never fix it itself
(ADR 0003's boundary, restated for OCR text specifically — an OCR'd inbound
letter is externally authored; an agent must not be the one that vouches for
its transcription), and a number that proves the correction loop is actually
improving quality (CER/WER), because nobody in this space publishes theirs.

## Decision

### 1. `KB_DIGITIZATION_REVIEW_ENABLED` — default OFF, both states tested (R43)

The flag gates the review UI's routes/screen (clean 404 when off) and the
HTTP review endpoints (`app/Http/Controllers/Api/Admin/KbReviewController.php`).
It does **not** gate MCP tool *registration*: `KnowledgeBaseServerRegistrationTest`
derives the tool roster from the files present in `app/Mcp/Tools/`
(`app/Mcp/Servers/KnowledgeBaseServer.php`'s own docblock explains why — a
config-dependent roster desyncs from the file list the moment a flag flips),
so `KbProposeTextCorrectionTool` and `KbReviewStatusTool` stay registered
with the flag off and answer `{disabled: true, flag: 'KB_DIGITIZATION_REVIEW_ENABLED'}`
rather than a 500 or a silent success. A test asserts both states for every
surface: HTTP 404 / MCP disabled-result off, HTTP 200 / MCP real-result on.

### 2. `kb_document_page_reviews` — tenant-aware, one row per document page

```
kb_document_page_reviews
  id                  bigint PK
  tenant_id           string(50), default 'default', indexed          (R31)
  knowledge_document_id  bigint FK -> knowledge_documents, cascade delete
  page_number         int, 1-based
  status              string: unreviewed | reviewed
  reviewed_by         nullable FK -> users
  reviewed_at         nullable timestamp
  created_at / updated_at
```

`UNIQUE(tenant_id, knowledge_document_id, page_number)` — a page has exactly
one current review state, upserted by `KbReviewService::markPageReviewed()`;
re-marking a page already `reviewed` is an idempotent no-op with a fresh
`reviewed_by`/`reviewed_at`, not a second row. Scoped by `forTenant()`
everywhere it is queried (R30); `BelongsToTenant` auto-fills `tenant_id` on
create (R31). Rows are removed by the FK cascade when the document is hard
deleted; a soft delete leaves them in place (the review history survives
retention exactly like the document row it describes, consistent with every
other tenant-aware child table in this codebase — `kb_document_page_reviews`
is not special-cased into a different retention story than, say,
`knowledge_document_acl`).

This table records **page-level** progress only. Document-level "reviewed"
is not a stored fact — it is derived (`KbReviewService::documentReviewSummary()`
reports counts, never a cached boolean) so there is nothing to keep in sync
when a correction re-chunks the document into a different page count.
**Approval** (§4) is the document-level fact, and it lives on
`knowledge_documents.generation_source`, not on this table — a document can
be approved with some pages still `unreviewed` (a reviewer decides the
unreviewed pages don't need a look, e.g. boilerplate cover pages); the UI
surfaces the count, it does not gate the approve button on it, because
gating would give the schema an opinion about review policy this ADR does
not need to take.

### 3. Review UI — original left, Markdown right, confidence heat-map

`frontend/src/features/admin/kb/review/*` (R11/R12/R15). The left pane
renders the ORIGINAL source (PDF.js for `application/pdf`, `<img>` for the
four OCR image MIMEs) when `source_retention` kept it (`full_copy`); for
`markdown_only` and `reference_only` — which by design drop or never copy
the original (ADR 0030 §5) — the left pane shows an explicit
`data-state="unavailable"` panel naming the retention mode, and **does not
attempt a re-fetch**: the connector credential that could re-download the
original belongs to the connector's own authorization context, not to the
reviewer's session, and a silent re-fetch would be a second, undeclared
egress path outside SEC-LLM-001's inventory. An unavailable original is a
recorded, visible state, not a broken feature — the right pane (Markdown,
raw or rendered-preview toggle, page navigator, confidence heat-map)
functions identically either way.

The **confidence heat-map** highlights spans (word- or line-granularity,
driver-dependent) whose `ocr_confidence` (chunk metadata, W1) falls under
`kb.review.low_confidence_threshold` (default 0.70) — a visual aid, not a
gate: nothing in this ADR blocks approval on confidence, the same reasoning
as the unreviewed-page count above.

**Save** on the right pane commits a correction through `DocumentIngestor` —
the identical re-ingest path every other document write takes (§1's third
context point). A full re-chunk and re-embed follow; `EmbeddingCacheService`
(keyed by chunk text hash) means pages whose text did not change cost no new
provider call, but this is a property of the cache, not a "page-only" save
— the whole document is re-ingested, exactly like any other correction to
canonical or non-canonical content already is.

### 4. Approval — a `generation_source` transition, branched on canonicity

**`approved` is not a new document status.** Approval is the existing
`auto → human` transition (ADR 0014), and it is **audited and transactional
in both branches** it can take:

```php
// App\Services\Kb\Review\KbReviewService::approve()
if ($document->is_canonical) {
    // Canonical: the existing explorer promotion IS this transition — it
    // also sets canonical_status = 'accepted' and writes the audit row.
    return $this->wikiExplorer->promote($document, $actor);
}

// Non-canonical (the ordinary OCR'd-scan case): the SAME generation_source
// flip and the SAME audit event_type, WITHOUT touching canonical_status.
// canonical_status is a canonical-only column (WikiExplorerService::promote()
// sets it unconditionally to 'accepted' whenever it is called — it has no
// is_canonical guard of its own, because every existing caller only ever
// calls it on a canonical row). Calling promote() on a non-canonical row
// would therefore stamp canonical_status='accepted' on a row scopeAccepted()
// never selects (it filters is_canonical=true first) — a value nobody
// reads, but a lie about the row's canonical state nonetheless. The two
// branches share the transition; they do not share the method.
```

Both branches write one `kb_canonical_audit` row (`event_type = 'promoted'`,
`actor` = the reviewer), in the same DB transaction as the column flip — no
"approved but unaudited" state is reachable. The non-canonical branch is
implemented directly in `KbReviewService`, not by relaxing
`WikiExplorerService::promote()`'s behaviour, because `promote()`'s
unconditional `canonical_status = 'accepted'` is correct for *its* callers
(the explorer only ever promotes canonical `auto` rows) and would be a
silent bug for this one.

**The review queue lists by tier and provenance, not by slug**:
`generation_source = 'auto' AND metadata->converter->provenance = 'ocr'`,
tenant-scoped. `WikiExplorerService::list()` — the existing "auto pages
awaiting review" listing — returns **slugged** (canonical) rows only and is
deliberately not reused: an OCR'd scan is non-canonical in the ordinary
case (no frontmatter), so a slug-based query would never surface it. The
review queue is therefore its own query against `knowledge_documents`,
sharing the scopes (`forTenant()`, the auto/ocr filter) but not the slug
requirement.

### 5. The reranker gap — `generation_source` adjustment for non-canonical rows too

`Reranker::canonicalAdjustment()` currently returns `{delta: 0, boost: 0,
penalty: 0}` for any non-canonical chunk before reaching the
`autoTierPenalty()` call that reads `generation_source`. W3 moves that one
read outside the `is_canonical` early-return — the **canonical boost/status
penalty stay canonical-only** (a `retrieval_priority` or
`canonical_status` reads meaningless on a non-canonical row), but the
auto-tier penalty is computed and applied regardless:

```php
private function canonicalAdjustment(array $chunk, float $priorityWeight): array
{
    $doc = $chunk['document'] ?? [];
    $penalty = $this->autoTierPenalty((string) ($doc['generation_source'] ?? 'human'));

    if (! (bool) ($doc['is_canonical'] ?? false)) {
        return ['delta' => -$penalty, 'boost' => 0.0, 'penalty' => $penalty];
    }

    $priority = (int) ($doc['retrieval_priority'] ?? 50);
    $boost = $priorityWeight * $priority;
    $penalty += $this->statusPenalty((string) ($doc['canonical_status'] ?? ''));

    return ['delta' => $boost - $penalty, 'boost' => $boost, 'penalty' => $penalty];
}
```

**Why this changes nothing for existing content.** The existing firewall
test's notion of "raw" is a non-canonical row, which today defaults to
`generation_source = 'human'` (only canonical frontmatter, or W1's OCR
write, can ask for `'auto'`). A `'human'`-default non-canonical row pays
`autoTierPenalty('human') === 0.0` — identical to before this change. The
**only** rows this re-orders are non-canonical `'auto'` rows — which, before
W1, did not exist at all (nothing wrote `generation_source = 'auto'` on a
non-canonical row until the OCR ingest path did). So: pre-existing content
ranks identically; an unreviewed scan now ranks *below* everything else at
its similarity score, including a reviewed sibling scan of the same
document family, which is exactly the invariant W3 must prove
(`reviewed_scan_outranks_unreviewed_scan` — a regression test with two
otherwise-identical chunks differing only in `generation_source`, strictly
comparing scores, per R16).

### 6. `KbProposeTextCorrectionTool` — the one MCP write toward the corpus, and it writes a candidate

The agent may point at a probable transcription error; it may never commit
one. `KbProposeTextCorrectionTool(document, page, old, new, rationale)`
follows the ADR 0003 `/suggest → /candidates → /promote` pattern precisely:
it writes a row to `kb_text_correction_candidates`, never to
`knowledge_documents` or its chunks. This is the only MCP tool this cycle
adds that writes *toward the corpus at all* — every other new tool (§8,
`KbReviewStatusTool`) is read-only.

Writing a candidate is still a mutating effect under SEC-AI-ACT-001, so it
carries the full mutating-tool control set:

- **Authorization before data access**: `McpToolAuthorizer` runs before the
  document lookup, using the immutable initiating identity and the current
  tenant/permission set (SEC-LLM-001 gate 5). The document is then resolved
  with `forTenant()` — a foreign-tenant `document` id is a 404, never a
  cross-tenant existence leak.
- **Bounded, validated input, stored as data, never as instruction**: `old`
  must occur **exactly once** on the named page (ambiguous or absent →
  refused, never "first match"); `new` ≤ 4 000 chars; `rationale` ≤ 500
  chars. All three are stored verbatim as candidate data — never
  interpolated into a prompt, a query, or executed.
- **Idempotency, DB-enforced**: the key is
  `sha256(tenant · user · document · version_hash · page · old · new)`, a
  `UNIQUE` column on `kb_text_correction_candidates`. `version_hash` is part
  of the identity deliberately — a candidate validated against one OCR pass
  must never be silently handed back as "the same candidate" for a later,
  different pass over the same page. A replayed call (identical key) returns
  the existing candidate; a genuinely concurrent double call is resolved by
  the unique constraint to exactly one row (the loser's insert fails and
  re-reads the winner's row — never two rows for one proposal).
- **Approval is single-use and atomic (R21)**: the candidate carries a
  persisted `status` (`pending → applied | rejected`) and `consumed_at`.
  The reviewer's approval runs in **one** DB transaction:
  `lockForUpdate()` on the candidate row (refusing any row whose `status`
  is no longer `pending` — the classic R21 shape, the lock and the
  status-check happening inside the same transaction the write commits in,
  never a separate round trip) **together with** the document/version row,
  re-validates `old` still occurs exactly once on the CURRENT version of the
  page (a correction proposed against version N must not silently apply to
  version N+1's different text), applies it (through `DocumentIngestor`,
  §3), writes the audit row, and marks the candidate `consumed_at`. Two
  reviewers approving the same candidate concurrently produce exactly one
  correction version and one audit row; the second transaction's
  `lockForUpdate()` sees `status != 'pending'` and the caller receives 409
  `already_consumed` — never a duplicate version, never a silent second
  no-op. A concurrent-approval regression test (two workers racing one
  candidate) is mandatory (R21's own "concurrency-sensitive services have a
  test that fires two workers" requirement).
- **Rate-limited and audited**: `KB_REVIEW_CANDIDATES_PER_HOUR` (default 60)
  caps proposals per user; every accepted, denied, and replayed call writes
  an audit row. The human confirmation IS the approval click in the UI — a
  candidate has zero effect on the corpus until a reviewer accepts it.
- **Negative tests, mandatory**: no identity, wrong tenant, `old` not found,
  `old` ambiguous (multiple occurrences), oversize `new`/`rationale`, replay,
  concurrent duplicate proposal, audit-write failure (fails closed — the
  candidate write and its audit row are one transaction; a failed audit
  means no candidate either, never a silent unaudited candidate).

### 7. `kb_text_correction_candidates`

```
kb_text_correction_candidates
  id                  bigint PK
  tenant_id           string(50), default 'default', indexed          (R31)
  knowledge_document_id  bigint FK -> knowledge_documents, cascade delete
  page_number         int
  version_hash        string(64) — the version this candidate was validated against
  old_text            text
  new_text            text (<= 4000 chars, enforced app-side)
  rationale           string(500) nullable
  idempotency_key      string(64) — sha256 of the concatenation of the per-field
                        sha256 digests of (tenant, user, document, version_hash,
                        page, old, new); hashing each field first keeps the key
                        injective (a plain concatenation of raw fields is not)
  status              string: pending | applied | rejected
  proposed_by          string — the MCP caller's immutable identity
  consumed_at          nullable timestamp
  consumed_by          nullable FK -> users (the reviewer who approved/denied)
  created_at / updated_at
```

`UNIQUE(idempotency_key)` is the replay/dedup mechanism (§6). No FK from
`kb_canonical_audit` to this table, consistent with every other row that
table records (ADR-independent: the audit trail is designed to survive hard
deletes of everything it describes, per its own no-FK-by-design rule) — the
correction's audit row carries the candidate id in `metadata_json` instead,
a pointer that may dangle after a hard delete, never a constraint that would
block one.

### 8. No MCP write of review status — a documented R44 exception

Page and document review status change **only** through the HTTP surface
(role-gated, an R32 matrix row) and the CLI (`kb:review {document} --page`).
`KbReviewStatusTool` is **read-only** — "what is reviewed, by whom" — the
explicit inverse of a pattern seen in comparable tools elsewhere
(`update_document_page` / `update_asset_review_status`-shaped write tools).

This is not caution for its own sake; it is ADR 0003's boundary restated for
this specific content class. A `provenance_tier: untrusted-external` OCR'd
inbound letter (ADR 0028) is, definitionally, text the platform did not
author and cannot vouch for. An agent that can *read* such a letter must
never be the one that marks it "reviewed" or "correct" — that action is a
human vouching for content whose authorship is not the platform's, and
letting an agent perform it would mean the model could clear its own citation
for grounding purposes. `KbProposeTextCorrectionTool` (§6) is the one
sanctioned way an agent participates: it may point at a probable error; only
a human can decide the pointed-at text is now right.

### 9. Measured: `CharacterErrorRateMetric` / `WordErrorRateMetric`

Two host-side metrics in `app/Eval/Metrics/`, implementing
`Padosoft\EvalHarness\Metrics\Metric` and registered by `EvalRegistrar` — the
same pattern `CitationGroundednessMetric` (R23) already establishes, so
`padosoft/eval-harness` needs no package change (upstreaming the two OCR
metrics is a separate, padosoft-scoped ask, not part of this cycle). Both
metrics compute against the **human-approved version as gold** — the
version whose `generation_source` is `'human'` after §4's approval — so a
CER/WER number only exists for documents that have actually been through
review; there is no "gold" before that, and no metric is computed for
documents still `'auto'`.

`eval:nightly` gains an `ocr` lane. A CER regression across a driver
upgrade (comparing the new run's transcription against the same
human-approved gold) fails the gate — the mechanism that turns "we changed
the OCR driver" into a measured decision instead of a hopeful one.

### 10. Workflow surface

The review queue is a `laravel-flow` definition with an approval node (flow
v2 is already a host dependency, used elsewhere in the platform); reviewers
work the queue through `flow-admin`. The v8.15 engagement suite counts
accepted corrections toward its existing badge mechanics — no new engagement
concept, an existing one fed a new event.

## Consequences

- A converted page has a place to be looked at, corrected, and approved —
  closing the review-workflow gap named in the audit that opened this cycle.
- The reranker firewall's `human > auto > raw` promise (ADR 0014) becomes
  true for non-canonical rows too, which is where essentially all OCR
  content lives. Reviewed scans now outrank unreviewed ones at equal
  similarity; nothing about pre-existing canonical ranking changes (§5).
- `kb_document_page_reviews` and `kb_text_correction_candidates` are two new
  tenant-aware tables; both cascade-delete with their parent document and
  neither is referenced by `kb_canonical_audit` (by design — the audit trail
  never depends on a table that might be gone).
- The agent gets a narrow, auditable, rate-limited way to flag a probable
  transcription error and genuinely zero way to mark anything reviewed,
  approved, or correct on its own authority — ADR 0003's line, drawn again
  for the specific shape of OCR review.
- CER/WER give this platform a number to publish about OCR correction
  quality that, per the audit, nobody in this space currently publishes.
- `KB_DIGITIZATION_REVIEW_ENABLED` stays default-OFF through this cycle;
  `KB_OCR_ENABLED` (ADR 0029) is expected to flip to default-ON once this
  metric has a baseline — the dependency the W1 plan text named up front.

## Surfaces (R44)

| Capability | PHP / CLI | HTTP | MCP |
|---|---|---|---|
| Mark a page reviewed / set status | `kb:review {document} --page={n} --status=` | `PATCH /api/admin/kb/documents/{id}/pages/{n}/review-status` (R32 matrix row) | — (documented R44 exception, §8) |
| Approve a document (`auto → human`) | `KbReviewService::approve()` | `POST /api/admin/kb/documents/{id}/approve` | — (write; human-only by design, §4/§8) |
| Save a correction (re-ingest) | `DocumentIngestor::ingestMarkdown()` (existing, reused) | `PATCH /api/admin/kb/documents/{id}/pages/{n}` | — (write; human-only, the review UI's Save) |
| Propose a text correction candidate | `KbReviewService::proposeCorrection()` | `POST /api/admin/kb/documents/{id}/corrections` | `KbProposeTextCorrectionTool` (propose only, §6) |
| Read review status | `kb:review {document} --page` (report mode) | `GET /api/admin/kb/documents/{id}/pages/{n}` | `KbReviewStatusTool` (read) |

All surfaces adapt one core, `KbReviewService`, tenant-scoped through
`KnowledgeDocument::forTenant()` (R30).
