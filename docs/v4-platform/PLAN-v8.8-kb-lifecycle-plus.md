# PLAN — v8.8 "KB Lifecycle Intelligence — Plus"

**Cycle:** `v8.8` · integration branch `feature/v8.8` (off `main` @ v8.7.0 GA `cf08b14`).
**Opened:** 2026-06-02 · **Owner:** auto-mode · **Discipline:** R37 (once-per-major merge),
R39 (rc-tag per Wn), R36 (cloud Copilot loop), R40 (local critic loop), R12/R13 (real
Playwright E2E), R14 (surface failures), R30/R31 (tenant isolation), R10 (canonical
awareness).

This cycle extends v8.7 ("KB Lifecycle Intelligence + Time Machine") with: the
**delete-trigger** for AI deep-analysis, a **per-(tenant, project) gate override**, plus three
gaps surfaced by the Affine buyer's-guide audit that Lorenzo green-lit —
**content-gap analytics**, **per-query multilingual FTS**, and the **chat-side graph panel**.

> Scope locked with Lorenzo 2026-06-02: "Consigliata + #5 + #6; niente #2 (SSO/SCIM) né #3
> (bulk export)". SSO/SCIM deferred to a dedicated v8.9/v9.0 cycle.

---

## Waves

### W1 — Suite stability + Affine audit  *(foundation — no app code)*
- **Fix the pre-existing flaky test.** 35 test files called `Mockery::close()` BEFORE
  `parent::tearDown()`; an unmet `->once()` expectation made `close()` throw, skipping the
  RefreshDatabase rollback → "active transaction" cascade across the next tests. Reordered all
  35 to rollback-first + added a `TenantContext` reset to the base `tests/TestCase.php::setUp()`.
  Verified: 6× full-suite random-order runs all green.
- **Affine line-by-line gap map** → `docs/v4-platform/AUDIT-2026-06-02-affine-buyers-guide-gap.md`.
- **New rule:** R41 (test-teardown ordering — rollback before Mockery::close). Fold into CLAUDE.md
  + a skill.
- *Tests:* the stability run IS the test. No new feature tests.

### W2 — Delete-trigger deep-analysis  *(task 2a)*
The AI deep-analysis fires on ingest/modify (v8.7). Add the **delete** trigger: when a doc is
removed, analyse the **obsolescence impact on the docs that referenced it**.
- **Pre-delete snapshot** in `DocumentDeleter` (content is gone after delete): capture the doc
  identity + incoming graph edges (`kb_edges.to_node_uid = slug`) + top-K semantic neighbours,
  BEFORE the soft-delete mutates state.
- `KbChangeAnalyzer::analyzeDeletion()` + a `deleted`-trigger prompt: "this doc is being removed —
  which of these referencing docs now have a dangling reference / need revising?" → structured
  `impacted_docs` output.
- New `AnalyzeDocumentDeletionJob` (async, cost-gated, reuses the v8.7 gate) dispatched from the
  delete path; persists `kb_doc_analyses` with `trigger='deleted'`; fires
  `EVENT_KB_DOC_ANALYSIS_READY`; audits `kb_canonical_audit`.
- *Gate:* honours the same canonical-default-ON / non-canonical-opt-in posture; respects the W3
  per-project override once it lands.
- *Tests:* PHPUnit — delete a doc with a referencing neighbour → job persists analysis +
  impacted list + event; `shouldNotReceive('chat')` when the gate is off (R26). Playwright — the
  Doc-Insights panel shows a `deleted`-trigger analysis card.

### W3 — Per-(tenant, project) analysis gate override  *(task 2b)*
Today the gate is global config (`canonical_default`/`non_canonical_default`). Add a DB override
scoped to `(tenant_id, project_key)`.
- Migration + model `KbAnalysisSetting (tenant_id, project_key, enabled?, canonical?,
  non_canonical?)` — nullable overrides; `project_key='*'` = tenant-wide. R31 (`tenant_id`
  mandatory) + composite unique `(tenant_id, project_key)`.
- `AnalyzeDocumentChangeJob::enabledFor()` (and the W2 deletion job) consult the override for
  `(tenant, project)` → fall back to config.
- Admin SPA: a small "Deep-Analysis Settings" screen listing the tenant's projects with toggles.
- *Tests:* PHPUnit — override ON for project A / OFF for project B in the same tenant → A
  analyses, B does `shouldNotReceive('chat')`; cross-tenant isolation (R30). Playwright — toggle a
  project, assert persisted + reflected. R32 RBAC matrix entry for the new admin route.

### W4 — Content-gap / search-failure analytics  *(Affine gap #1)*
Surface the questions the KB could NOT answer so editors can close the gaps.
- A `kb_search_failures` rollup (tenant+project scoped) fed from the **refusal gate** (already
  logged) + zero-result retrievals: query text, normalized form, count, last-seen, reason
  (refused / zero-result / low-confidence).
- Admin "Content Gaps" panel: ranked failing queries → one-click **"draft article"** that reuses
  the existing `promotion-suggest` flow (writes nothing — ADR 0003 human-gated).
- *Tests:* PHPUnit — a refused chat turn + a zero-result query record rows; the panel API ranks by
  count desc (R16 strict-monotonic fixture). Playwright — panel happy path + empty state + the
  draft-article hand-off. R30/R31 tenant scoping.

### W5 — Per-query multilingual FTS  *(Affine gap #5 / roadmap R24)*
Detect the query language and use the matching Postgres FTS dictionary instead of a single fixed
config language.
- A lightweight language detector (config-driven allow-list; default falls back to the configured
  language — never guesses wildly). The per-doc `language` column already exists.
- `KbSearchService` builds `to_tsquery(<detected-lang>, …)` per query; pgvector path unchanged;
  SQLite test path no-ops gracefully (R9 — FTS is pgsql-only).
- *Tests:* PHPUnit — an Italian query routes to the `italian` dictionary, English to `english`;
  unknown language falls back to default (R14 — never silent wrong-dictionary). Feature test on
  the RAG hot path (not just a unit test, per §8).

### W6 — Chat-side related-graph panel  *(Affine gap #6 / roadmap R10)*
Mount the long-deferred chat-side graph panel: show the documents related to the answer's
citations via `kb_edges` / `GraphExpander`.
- BE: a small endpoint returning the 1-hop neighbours of the answer's cited canonical docs
  (reuses `GraphExpander`, config-gated, no-op when no canonical docs).
- FE: a collapsible "Related" panel in the chat surface (R11 testids, R14 states, R15 a11y).
- *Tests:* PHPUnit — endpoint returns neighbours for a cited canonical doc, empty for
  non-canonical. Playwright — panel renders related nodes on a grounded answer + empty state when
  none.

### W7 — RC + GA

> **MANDATORY final-step gate (Lorenzo, 2026-06-02):** before the GA tag +
> release, do a **precise section-by-section README audit** — walk EVERY
> README section and update it with all the latest features shipped this cycle
> (delete-trigger analysis, per-project gate, content-gap analytics, multilingual
> FTS, chat-graph panel) AND refresh the **competitive-differentiator** content
> (comparison tables / "why us" sections) so nothing shipped is missing and the
> positioning vs competitors is current. This is on top of the R39 roadmap-row
> flip + Changelog entry.

- Per-Wn R39 rc-tags accumulate (`v8.8.0-rc1` after W1 … etc.).
- README `Key Features` + `Changelog` refresh + **roadmap-row flip** (R39 anti-pattern guard).
- R37 GA merge `feature/v8.8 → main` + `v8.8.0` tag + GitHub Release.
- ADR for the new architecture decisions (delete-trigger snapshot; per-project gate; multilingual
  FTS routing). Fold discoveries into CLAUDE rules/skills.

---

## Per-Wn protocol (every sub-PR)
1. Sub-branch `feature/v8.8-W{n}-…` off `feature/v8.8` (R37).
2. Implement with **real PHPUnit + real Playwright** (R12/R13 — real backend/DB, external-only stubs).
3. Local tests green → **local Copilot-CLI critic loop (R40)** until `0 must-fix`.
4. `gh pr create --reviewer copilot-pull-request-reviewer` → CI green → **cloud Copilot loop (R36)**
   until 0 outstanding + all CI green.
5. **Auto-merge** when R36 conditions met; re-request review after each push.
6. Maintain this PLAN + per-Wn `STATUS-*.md` + running `LESSONS-v8.8.md`.
7. Per-Wn **R39 rc-tag** + README refresh.

## Deferred (NOT this cycle)
- SSO/SCIM (Affine gap #2) → dedicated v8.9/v9.0 cycle (enterprise unlock, ~1 full cycle).
- Bulk export / portability (Affine gap #3).
- Helpdesk/CRM connectors + ticket-deflection (Affine gap #4, Pro-tier).
