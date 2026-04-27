# AskMyDocs v3.0 — LESSONS digest

**Period:** 2026-04-26 → 2026-04-27 (~2 days, 5 milestones, ~30 sub-tasks)
**Sub-tasks executed:** T1.1..T1.8 (M1) · T2.1..T2.10 (M2) · T3.1..T3.8 (M3) · T2.7-FE..T3.6/T3.7 + T2.10-FE (M2-FE/M3-FE) · plus orphan recovery + dedupe fix
**Source:** [docs/v3-platform/LESSONS.md](./LESSONS.md) (append-only, 28 entries: T1.x date-stamped + L17..L28 numbered)

---

## 1. Patterns emerged → permanent rules (CLAUDE.md R23..R29)

These are recurring high-impact patterns that future PRs should follow without re-deriving the rationale every time.

### R23 — Pluggable pipeline: `supports()` mutex, `bootInstance()` validates FQCN
**Origin:** T1.1 + T1.4 + T1.7 lessons
**Statement:** Every interface registry (`PipelineRegistry`, future MCP-tool registry, future provider registry) MUST validate at boot that each registered FQCN actually implements the expected interface, AND its `supports()` predicates MUST NOT overlap with another registered class's `supports()`.
**Why:** First-match-wins resolution silently picks the wrong handler when overlap exists (caught by T1.7's PdfPageChunker re-routing test).
**How to apply:** New registry-style component → mirror `app/Services/Kb/Pipeline/PipelineRegistry::bootInstance()`. Test the overlap detection explicitly.

### R24 — Per-reason i18n with generic fallback; machine-readable tag stays English
**Origin:** L22 (BE) + L23 (FE)
**Statement:** Growing user-visible taxonomies (refusal reasons, validation errors, audit-event labels) use a hierarchical `kb.refusal.{reason}` key with a generic fallback at the parent path. The machine-readable identifier (`refusal_reason` tag) NEVER localizes — only the human-visible body does. FE renders BE-localized strings verbatim; no parallel FE i18n surface for the same content.
**Why:** Two translation surfaces drift. New code adding a reason without lang line should still produce sensible UI (fallback). Locale switching has one source of truth.
**How to apply:** Use `localizedRefusalMessage($reason)` pattern. Test the fallback (reflection on the helper). Never reach for FE i18n keys when the BE already delivers the localized string.

### R25 — Optimistic mutations: dedupe by id when merging server response
**Origin:** L28
**Statement:** Any TanStack Query (or equivalent) `onSuccess` that merges optimistic + server response MUST filter the cache by BOTH the optimistic id AND the server response's id, before appending. The merge is idempotent: same id appears at most once.
**Why:** A cache that already contains the server-response id (from prior refetch race or fixture seed) duplicates without this filter. Visible as duplicate UI elements for ~100ms.
**How to apply:** New optimistic mutation → write the dedupe filter on the `onSuccess` setQueryData. Test with strict-mode locators (no `.first()`).

### R26 — Refusal short-circuit must NEVER call the LLM; prove it with `shouldNotReceive`
**Origin:** L19
**Statement:** Any controller path that ought to skip an external API call when local conditions don't warrant it (refusal threshold, cost guard, rate limit) MUST be proved by Mockery's `shouldNotReceive('chat')` (or equivalent), NOT `Http::assertNothingSent()`. Transport-agnostic; fails loudly on regression.
**Why:** A refusal that still pays the API cost is worse than no refusal — pays the cost AND ships a hallucination. The invariant is the WHOLE point of the feature.
**How to apply:** Inject expensive collaborator via Mockery; assert `shouldNotReceive` on the method that would fire. Mirror across every controller hitting the same external (`KbChatController` + `MessageController`).

### R27 — Response-shape extensions are ADDITIVE only; never sub-objectify shipped keys
**Origin:** L21
**Statement:** Extending a JSON response with new data: ADD new keys with sensible defaults; NEVER rename or sub-objectify a primitive callers may already read. New sub-structure goes under `<key>_breakdown` (or `<key>_details`) as a sibling. Refusal/error paths emit the same shape with sentinel values; never strip keys based on path.
**Why:** A shipped key that morphs from int to object silently breaks every existing client. Sub-objectifying after ship is a one-way door.
**How to apply:** Add `meta.foo` to extend; never modify `meta.foo`. Test the additive contract: `assertIsInt('meta.latency_ms')` after extension.

### R28 — Per-project unique slugs + ALWAYS cascade m2m pivot delete
**Origin:** L27
**Statement:** Per-project taxonomies (tags, categories, custom labels) backed by m2m pivot tables MUST: (a) declare composite UNIQUE on `(project_key, slug_or_name)` — never global; (b) declare FK with `cascadeOnDelete()` on the pivot; (c) reject `project_key` change on update with 422 (orphan-pivot guard); (d) test the cascade explicitly (`assertDatabaseMissing` on pivot rows after parent delete).
**Why:** Global slug uniqueness blocks two tenants picking the same intuitive name. Pivot orphan rows make the FE crash on undefined relationships.
**How to apply:** New per-project taxonomy → migration with composite UNIQUE + FK cascade. Controller rejects `project_key` change. Feature test asserts the cascade.

### R29 — testid hierarchy: `feature-resource-{id}-{action[-substep]}`
**Origin:** L24 + L26 + L27 (codified together)
**Statement:** Every interactive admin or chat surface uses the testid hierarchy `feature-resource-{id}-{action[-substep]}` for stable, hierarchical, grepable Playwright + Vitest selectors. Examples: `admin-tag-row-42-delete-confirm`, `chat-filter-preset-7-load`, `filter-chip-source-pdf-remove`.
**Why:** Predictable selectors survive DOM refactors. Cross-feature memorisation isn't required when convention holds.
**How to apply:** New CRUD surface → follow the convention. Composer-style trigger: `chat-filter-bar-add`, popover: `filter-popover`, tab: `filter-tab-{dim}`, option: `filter-{dim}-option-{value}`. Same pattern from chat composer to admin maintenance panel.

---

## 2. Patterns that became skills

### Skill: `pluggable-pipeline-registry`
**Origin:** T1.1 + T1.4 + T1.7
**Trigger:** Use when adding a new converter / chunker / source-type to the ingestion pipeline (or any registry-based dispatch surface).
**Path:** `.claude/skills/pluggable-pipeline-registry/SKILL.md`
**Body topics:** FQCN validation at boot, `supports()` mutex check, first-match-wins ordering, test that overlapping `supports()` is detected.

### Skill: `optimistic-mutation-dedupe`
**Origin:** L28
**Trigger:** Use when writing a TanStack Query / Redux / Zustand `onSuccess` that merges optimistic placeholder + server-confirmed payload.
**Path:** `.claude/skills/optimistic-mutation-dedupe/SKILL.md`
**Body topics:** Filter both optimistic id AND server response id; idempotent-merge contract; strict-mode locator test posture.

### Skill: `refusal-not-error-ux`
**Origin:** L19 + L20
**Trigger:** Use when implementing a deterministic refusal path (anti-hallucination, quota guard, cost short-circuit).
**Path:** `.claude/skills/refusal-not-error-ux/SKILL.md`
**Body topics:** `shouldNotReceive` test, sentinel detection via `=== trim()`, role=status (NOT alert), per-reason i18n hierarchy.

---

## 3. Project-internal lessons (digest only, no permanent rule)

These are valuable but specific enough to a single feature that they don't generalize into a CLAUDE.md rule:

- **T1.1** — `final readonly` + constructor-property-promotion DTO shape (PHP 8.3+ idiom; no need to codify).
- **T1.2 cycle-2 addendum** — Orchestrator workflow gotchas (worktree state hygiene; specific to the orchestrator runner).
- **T1.3** — Doc-only fixes don't burn the cycle ceiling (Copilot review policy nuance).
- **T1.4 cycle revision** — Multi-task chain override of strict cycle-4 ceiling (orchestrator policy nuance).
- **T1.5** — Inline pure-PHP PDF fixture builder pattern (no committed binaries — already a project convention codified in PR template).
- **T1.6** — PhpWord Title element gotcha (library-specific quirk).
- **T1.8** — SourceType enum is helper-only, column stays string (specific back-compat decision).
- **T2.1** — Reflection-based SQL inspection for testing on SQLite (test-pattern for pgvector compat).
- **T2.2** — KbChatRequest FormRequest threading filters DTO (specific request shape).
- **T2.3** — Tag filter via `whereExists` subquery (specific query construction).
- **T2.4** — fnmatch + `**` cross-segment glob translator (specific helper, lives in KbPath).
- **T2.5** — Empty-array filter dimensions are no-ops by current implementation (implementation note).
- **T2.6** — LIKE escape pairs with explicit `ESCAPE '\\'` clause (codified in skill `input-escape-complete` already).
- **T2.9** — Per-user `where('user_id', auth()->id())` + 404-not-403 (codified in existing rule R-policies; not new).
- **L17** — Nullable + non-indexed analytics columns by default (DB design idiom).
- **L18** — Weighted-sum confidence formula + producer-side clamp (formula-specific).
- **L20** — `=== trim()` sentinel detection (covered by `refusal-not-error-ux` skill).
- **L23** — FE renders BE-localized strings verbatim (covered by R24 above).
- **L24** — Stateless component, lifted state pattern (covered by R29's testid hierarchy + general React idiom).
- **L25** — Mention popover cursor-context detection (specific to inline-trigger autocomplete).
- **L26** — CRUD confirm step + inline 422 (covered by R29 + general UX rule).

---

## 4. Open / unresolved (follow-up candidates for v3.1+)

- **FE i18n library**: when the project decides to localize FE-only strings (button labels, helper text, ARIA labels for ConfidenceBadge tier names), pick `react-i18next` (most mainstream) and mirror the BE's `localizedRefusalMessage()` hierarchy as the source-of-truth contract.
- **Anti-hallucination tier-2** (per design doc §2.2 Strato 2): citation-grounded answer expansion, multi-pass verification, abstention metrics dashboard.
- **MCP graph tools** (per design doc §2.6): the 10-tool spec for graph navigation via MCP — backend complete, MCP wrapper pending.
- **Connector storage layer** (M4 of design doc, NOT this M4): hybrid OAuth-workspace connectors for Google Drive / Confluence / Notion.
- **Admin Tags suggestions tab** (T2.10 deferred half): AutoTagger v3.1 + suggestions UI placeholder.
- **Composer redesign UX polish**: Lorenzo's visual review may surface tweaks (chip colours dark mode, popover positioning at viewport edges, mobile touch targets).

---

## Closing summary

| Metric | v3.0 |
|---|---|
| Sub-tasks executed | ~30 (T1.1..T1.8 + T2.1..T2.10 + T3.1..T3.8 + recovery + dedupe-fix) |
| PRs merged | ~25 (sub-PRs + macro PRs + closeouts + status reports + recovery) |
| LESSONS entries | 28 (T1.1..T2.9 date-stamped + L17..L28 numbered) |
| New permanent rules (R23..R29) | 7 |
| New skills | 3 |
| Suite at end | PHPUnit **985/3017**, Vitest **224/224**, Playwright **28/28** |
| Net new tests in v3.0 | **+550 assertions** (~245 BE + ~75 Vitest cases + 28 Playwright scenarios) |

**v3.0 ready to ship after T4.6** (final E2E verification + release closure PR).

This digest pattern should be repeated at the start of M4-equivalent for every future v3.X release — the digest is what keeps LESSONS.md scalable as the project grows.
