# DESIGN v4.7 — Tabular Review + Workflows + AI-suggest

**Author:** Lorenzo Padovani + Claude (assisted)
**Date:** 2026-05-12
**Status:** Accepted (2026-05-12) — locked-in per `docs/v4-platform/ROADMAP-v4-v5-v6.md` "v4.7" section. Inserts a NEW cycle between v4.6 (package extraction) and v5.0 (agentic).
**Inspiration:** github.com/willchen96/mike (Mike, AGPL-3.0, 2.8k stars on GitHub, mikeoss.com), a legal-domain document assistant with two killer features absent from Glean / Notion AI / ChatGPT Enterprise / M365 Copilot / Mendable / Vectara. We import the two features, generalise them beyond legal, and add an AI-suggest layer Mike does NOT have.

## 1 — Why this cycle exists

Two competitor-absent features are worth shipping:

1. **Tabular Review** — spreadsheet-style document extraction. Define columns by prompt; every cell across hundreds of docs auto-extracted in parallel, every cell cited back to a chunk/page/quote; flag-coloured for at-a-glance evidence strength.
2. **Workflows** — reusable prompt templates (type `assistant` for chat or `tabular` for table extraction). Share firm-wide; juniors run them in one click.

**AskMyDocs differentiator (NOT in Mike):**
3. **AI-suggest workflows from user's own KB** — sample the tenant's documents, analyse frontmatter patterns captured by v4.5 W5.5 source-aware ingestion, ask the LLM to propose 5 workflow templates the user would actually want. Triggers: first-run banner / weekly periodic / on-demand button / recurring-chat-query detection.

## 2 — Mike's design patterns (derived from open-source code review)

The following schema and pipeline patterns are derived from reviewing Mike's publicly available AGPL-3.0 codebase. The designs below are AskMyDocs's own independent interpretation and adaptation, generalised beyond the legal vertical.

### Tabular Review schema (AskMyDocs adaptation)
```sql
tabular_reviews (id, project_id, user_id, title, columns_config jsonb, workflow_id?, shared_with jsonb, created_at, updated_at)
tabular_cells (id, review_id, document_id, column_index, content text, citations jsonb, status, created_at)
```

`columns_config` = `[{index, name, prompt, format?, tags?}]` where format is one of `text | bulleted_list | number | percentage | monetary_amount | currency | yes_no | date | tag`. The format value gets injected as a prompt suffix to enforce output shape.

`tabular_cells.content` = JSON `{summary, flag: green|grey|yellow|red, reasoning}`.

`citations` = inline `[[page:N||quote:excerpt]]` in summary + separate `<CITATIONS>` XML block for the agentic chat that can read cells.

### Extraction pipeline pattern
1. For each `(document, columns)` pair: build a single LLM prompt with ALL columns + their format suffixes.
2. LLM streams a JSON line per column (multi-column extraction in single call → cost = 1 chiamata × N doc, not N×M).
3. Each line parsed → upsert `tabular_cells.content` and stream SSE to the client.

### Workflows schema (reference)
```sql
workflows (id, user_id, title, type, prompt_md, columns_config jsonb, practice, is_system, created_at)
workflow_shares (workflow_id, shared_by_user_id, shared_with_email, allow_edit)
hidden_workflows (user_id, workflow_id)
```

3 built-in templates: CP Checklist / Credit Agreement Summary / Shareholder Agreement Summary — all legal-vertical.

## 3 — AskMyDocs adaptation

### Schema (4 new tables — all R30/R31 tenant_id mandatory)

```sql
-- Tabular reviews
CREATE TABLE tabular_reviews (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id varchar(50) NOT NULL DEFAULT 'default',
  project_key varchar(100) NOT NULL,
  user_id bigint NOT NULL REFERENCES users(id),
  title varchar(200) NOT NULL,
  columns_config jsonb NOT NULL,
  workflow_id uuid NULL REFERENCES workflows(id) ON DELETE SET NULL,
  shared_with_user_ids jsonb NOT NULL DEFAULT '[]'::jsonb,
  practice varchar(100) NULL,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  INDEX idx_tabular_reviews_tenant_project (tenant_id, project_key)
);

CREATE TABLE tabular_cells (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id varchar(50) NOT NULL DEFAULT 'default',
  review_id uuid NOT NULL REFERENCES tabular_reviews(id) ON DELETE CASCADE,
  document_id bigint NOT NULL REFERENCES knowledge_documents(id) ON DELETE CASCADE,
  column_index smallint NOT NULL,
  content jsonb NULL,        -- {summary, reasoning, citations[]}
  flag varchar(10) NULL,      -- green | grey | yellow | red
  status varchar(15) NOT NULL DEFAULT 'pending',  -- pending | generating | done | error
  generated_at timestamptz NULL,
  UNIQUE (review_id, document_id, column_index),
  INDEX idx_tabular_cells_tenant_review (tenant_id, review_id)
);

-- Workflows
CREATE TABLE workflows (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id varchar(50) NOT NULL DEFAULT 'default',
  user_id bigint NULL REFERENCES users(id),  -- NULL for is_system=true
  title varchar(200) NOT NULL,
  type varchar(20) NOT NULL,  -- 'assistant' | 'tabular'
  prompt_md text NULL,
  columns_config jsonb NULL,
  practice varchar(100) NULL,
  is_system boolean NOT NULL DEFAULT false,
  description text NULL,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  INDEX idx_workflows_tenant_user (tenant_id, user_id)
);

CREATE TABLE workflow_shares (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  workflow_id uuid NOT NULL REFERENCES workflows(id) ON DELETE CASCADE,
  shared_by_user_id bigint NOT NULL REFERENCES users(id),
  shared_with_user_id bigint NOT NULL REFERENCES users(id),  -- internal users only, no email
  allow_edit boolean NOT NULL DEFAULT false,
  created_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (workflow_id, shared_with_user_id)
);

CREATE TABLE hidden_workflows (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id varchar(50) NOT NULL DEFAULT 'default',
  user_id bigint NOT NULL REFERENCES users(id),
  workflow_id uuid NOT NULL REFERENCES workflows(id) ON DELETE CASCADE,
  created_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (tenant_id, user_id, workflow_id)
);
```

**Deviation from Mike**: `workflow_shares` uses `shared_with_user_id` (internal users) instead of `shared_with_email` (external emails). AskMyDocs is an enterprise installation with users in the same tenant — internal user IDs are stronger ACL than email addresses.

### Service layer

```
app/Services/Tabular/
├── TabularReviewService.php          # CRUD + access control
├── TabularReviewExtractor.php        # the batch streaming engine
├── ColumnFormatRegistry.php          # format → prompt-suffix mapper
├── CitationFormatter.php             # inline-citation parser/formatter
└── FlagClassifier.php                # decides green/grey/yellow/red from extraction confidence

app/Services/Workflows/
├── WorkflowService.php               # CRUD + sharing + hide
├── WorkflowAccessControl.php         # Spatie roles + share-aware ACL
├── WorkflowSuggester.php             # AI-suggest from KB (the AskMyDocs differentiator)
├── MetadataPatternAnalyzer.php       # extracts frontmatter patterns from sampled docs
└── BuiltinWorkflowSeeder.php         # seeds the 15 templates
```

### TabularReviewExtractor pipeline

For each `(document, column[])` pair:

```
1. KbSearchService::searchWithContext($column.prompt, $document_id, top_k=8)
     → returns top-K chunks from THAT document matching the column prompt
2. ColumnFormatRegistry::resolve($column.format)
     → returns prompt suffix like "Respond with a single number, nothing else."
3. v4.5 W5.5 FRONTMATTER SHORTCUT — check if chunk metadata has $column.name as a direct key
     → if YES (e.g. column.name = "status" AND chunk.metadata['status'] = "In Progress"):
        emit cell with flag=green, summary=chunk.metadata['status'], reasoning="from source metadata"
        NO LLM CALL — instant + free
     → if NO: fall through to LLM
4. AiManager::chat(messages with chunks + column prompts + format suffixes) STREAMING
     → LLM returns JSON line per column: {"column_index": N, "summary": "...", "flag": "...", "reasoning": "...", "citations": [{chunk_id, quote, offset}]}
5. Stream each line to client via SSE; upsert tabular_cells row on the fly.
6. Persistent state machine: pending → generating → done | error (re-runnable).
```

**Multi-column batching**: ALL columns for a single document fit in one LLM call (Mike's pattern). Cost = `O(documents)` not `O(documents × columns)`.

### CitationFormatter

Mike: `[[page:N||quote:excerpt]]`. We extend:
```
[[doc:DOC_ID||chunk:CHUNK_ID||heading:section_path||quote:excerpt]]
```

Inline in `cell.content.summary`. Click-to-open in admin SPA opens the source doc viewer scrolled to that chunk, with the quote highlighted.

### FlagClassifier

```
green  — extraction confident, chunk vector-sim > 0.85, single unambiguous chunk
grey   — no evidence found; "Not present in this document"
yellow — multiple chunks, conflicting answers; reasoning explains the conflict
red    — extraction failed (LLM refused, JSON parse error, format mismatch)
```

### WorkflowSuggester — the AskMyDocs differentiator

```php
class WorkflowSuggester {
    public function suggestForTenant(string $tenantId, int $count = 5): array {
        // 1. Stratified sample 50 docs across source_types (Notion / Drive / Confluence / etc.)
        $sample = KbSampleService::stratifiedSample($tenantId, n: 50, by: 'source_type');
        
        // 2. Extract distinct frontmatter keys + chunk.metadata.search_tags
        $patterns = MetadataPatternAnalyzer::extract($sample);
        // → ['recurrent_keys': ['status', 'owner', 'project', 'tags'],
        //    'recurrent_tags': ['decision', 'architecture', 'incident', 'okr'],
        //    'source_distribution': ['notion': 60%, 'confluence': 30%, 'drive': 10%],
        //    'title_clusters': ['Project Status ...', 'ADR ...', 'Incident Post-mortem ...']]
        
        // 3. Cluster chat_logs questions if available (recurring user intent)
        $recurringQueries = ChatLogPatternAnalyzer::topRecurring($tenantId, days: 30, n: 10);
        
        // 4. Prompt LLM with patterns + clusters + sample titles, ask for $count proposed workflows
        $llmProposals = $this->ai->chat([
            ['role' => 'system', 'content' => $this->systemPromptForWorkflowSuggester()],
            ['role' => 'user',   'content' => json_encode([
                'frontmatter_patterns' => $patterns,
                'recurring_user_queries' => $recurringQueries,
                'sample_titles' => $sample->pluck('title'),
            ])],
        ]);
        
        // 5. Validate proposal JSON shape, return as draft workflows the user can preview + save
        return WorkflowProposal::validate($llmProposals);
    }
}
```

The proposed workflows are NOT auto-saved — they're shown in a dedicated UI ("AI Suggestions ✨") with preview. User picks → 1-click save.

### 15 built-in templates (seeded by `BuiltinWorkflowSeeder`)

| # | Title | Type | Columns / Surface |
|---|---|---|---|
| 1 | Project Status Review | tabular | Name, Status, Owner, Last Update, Blockers, Next Milestone |
| 2 | Decision Audit | tabular | Decision Title, Date, Decider, Rationale, Outcomes, Reversed? |
| 3 | Incident Postmortem Index | tabular | Date, Severity, Root Cause, Impact, MTTR, Action Items |
| 4 | Compliance Checklist (GDPR/AI-Act) | tabular | Requirement, Status, Evidence, Last Review, Owner |
| 5 | Vendor Risk Review | tabular | Vendor, Service, SLA, Data Categories, Last Audit, Contract End |
| 6 | Meeting Notes Summary | assistant | (free-form chat workflow) |
| 7 | Document Summary by Heading | tabular | Heading, Summary, Key People, Action Items |
| 8 | Architecture Decision Records Index | tabular | ADR Number, Title, Status, Date, Superseded By |
| 9 | OKR Tracker | tabular | Objective, Key Result, Owner, Q-Target, Q-Actual, Status |
| 10 | Customer Feedback Themes | tabular | Date, Customer, Theme, Sentiment, Action Required |
| 11 | Patent Box Tracker | tabular | Activity, Date, Hours, Worker, Output, Status (ties to patent-box-tracker package) |
| 12 | CP Checklist (legal) | tabular | Index, Clause Number, Clause, Status (ported from Mike) |
| 13 | Credit Agreement Summary (legal) | assistant | (ported from Mike) |
| 14 | Shareholder Agreement Summary (legal) | assistant | (ported from Mike) |
| 15 | PII Audit Review | tabular | Detector, Hits, Sample (redacted), Source Doc, Last Reviewed |

## 3a — Format types: 16 (Mike has 9) + UX differentiators reviewed from real screenshots

Reviewed Mike's actual tabular UI 2026-05-12 from screenshots Lorenzo shared. UX-vincente from Mike absorbed; gaps identified.

### Format types (column-level)

Mike ships 9 (`text | bulleted_list | number | percentage | monetary_amount | currency | yes_no | date | tag`). AskMyDocs ships **16** total — 9 Mike-compatible + 7 new.

| Format | Cell render | LLM prompt suffix | Validator | Beats Mike how |
|---|---|---|---|---|
| `text` | Free text | "Respond with a concise text answer (max 200 chars)." | length cap | = |
| `bulleted_list` | `- foo\n- bar` indented | "Respond as a markdown bulleted list (- items)." | starts with `- ` | = |
| `number` | Right-aligned number | "Respond with a single number, nothing else." | regex `^-?\d+(\.\d+)?$` | = |
| `percentage` | `XX%` | "Respond with a percentage in 0-100% (one digit decimal allowed)." | `^\d{1,3}(\.\d)?%$` | = |
| `monetary_amount` | locale-formatted | "Respond with monetary amount + ISO currency code." | regex | = |
| `currency` | ISO currency code | "Respond with a 3-letter ISO currency code." | enum ISO-4217 | = |
| `yes_no` | Pill (green/red) | "Answer Yes or No only." | enum `{Yes, No}` | = |
| `date` | locale ISO date | "Respond with an ISO-8601 date YYYY-MM-DD." | date parse | = |
| `tag` | Single colored pill, hash-derived palette | "Respond with one short label (1-3 words)." | length cap | + auto-color (Mike has color but inconsistent) |
| **`enum`** ★ | Colored pill, value FROM `column.enum_values`, palette deterministic by hash | "Answer with EXACTLY one of: {enum_values list}." | strict enum membership | Mike's `tag` accepts free text; ours validates |
| **`enum_status`** ★ | Status pill with semantic colors (todo=grey, wip=yellow, done=green, blocked=red) | "Answer with one of: todo / in_progress / done / blocked." | enum + semantic palette | Mike: ❌ |
| **`rating`** ★ | 1-5 stars OR 0-100% bar | "Respond with a rating 1-5 (1=worst, 5=best)." | regex `^[1-5]$` | Mike: ❌ |
| **`url`** ★ | Clickable link with favicon | "Respond with a single URL (https://...)." | URL parse | Mike: ❌ |
| **`person`** ★ | Avatar + name chip, click-to-profile | "Respond with the person's email or full name." | tenant-user lookup | Mike: ❌ |
| **`tags_multi`** ★ | Multi-chip stack | "Respond with multiple short labels separated by commas." | array length cap | Mike: ❌ (only single tag) |
| **`relation`** ★ | Doc-title chip, click-to-open in side panel | "Respond with the doc title or doc-id from this KB." | KB doc resolve | Mike: ❌ |
| **`json_path`** ★ | Renders raw value as text/pill based on detected type | NO LLM CALL — pulls from chunk.metadata via JSONPath | path resolve | Mike: ❌ (free + instant) |

★ = new vs Mike.

### Column config schema extension

```php
columns_config = [
    {
        index: 0,
        name: "Direction",
        prompt: "Is the NDA Mutual or Unilateral?",
        format: "enum",
        enum_values: ["Mutual", "Unilateral", "Other"],  // new for enum/enum_status/tags_multi
        json_path: null,                                  // populated when format = json_path
        verified_human_lock: false,                        // if true, regenerate skips this column for human-verified cells
    },
    {
        index: 1,
        name: "Status (from Notion)",
        prompt: null,                                      // unused for json_path
        format: "json_path",
        json_path: "$.notion.properties.status",           // direct lookup, no LLM
        enum_values: null,
    },
]
```

### Cell schema extension

```php
tabular_cells = [
    ...,
    flag: "green|grey|yellow|red",
    confidence_score: 0.87,                              // NEW — from Reranker; rendered as bar/percentage
    verified_by_user_id: bigint NULL,                    // NEW — when set, cell is human-locked
    verified_at: timestamptz NULL,                        // NEW
    previous_content: jsonb NULL,                         // NEW — last value before re-generate (cell-level diff)
    edited_history_json: jsonb,                           // NEW — append-only history of edits
]
```

### New audit table

```sql
CREATE TABLE tabular_cell_audit (
  id uuid PRIMARY KEY,
  tenant_id varchar(50) NOT NULL,
  cell_id uuid NOT NULL REFERENCES tabular_cells(id) ON DELETE CASCADE,
  user_id bigint NULL,
  event_type varchar(30) NOT NULL,    -- 'generated' | 'regenerated' | 'human_edited' | 'human_verified' | 'human_unlocked' | 'flag_overridden'
  before_content jsonb NULL,
  after_content jsonb NULL,
  metadata_json jsonb,
  created_at timestamptz NOT NULL DEFAULT now()
);
```

### 12 UX differentiators (priority labelled)

| # | Differentiator | Priority | Ships in |
|---|---|---|---|
| 1 | `json_path` shortcut — no LLM call when value is in chunk metadata | **must** | W1 |
| 2 | Human-verified lock — manual edit + checkbox makes cell immutable + audited | **must** | W1 |
| 3 | Cell-level diff — regenerate shows before/after, user accepts new or keeps old | **must** | W1 |
| 4 | Bulk cell selection — ctrl+click multi-select → regenerate subset | **must** | W1 |
| 5 | Citation popover as side-panel (not overlay) — opens KbDocumentController::raw viewer, scroll-to-chunk, sibling chunks visible | **must** | W1 |
| 6 | Cell-level conversation — "💬 Discuss this cell" opens scoped chat side-pane | **should** | W2 |
| 7 | AI-suggest columns — given selected docs, propose 5-10 extra useful columns | **should** | W2 |
| 8 | Confidence score render — Reranker score as bar/percentage + opacity tint | **should** | W1 |
| 9 | Group-by column header — collapsable sections by column value | **could** | W3 |
| 10 | Pivot mode — transpose docs/columns axes | **could** | W3 |
| 11 | Column templates marketplace — single columns reusable across reviews (not just whole workflows) | **could** | W3 |
| 12 | Real-time collab cursors — multiple users see cell-edits live | **wont** | v4.8 backlog |

Priority labels follow MoSCoW: must (ships) / should (ships) / could (ships if time) / wont (parked for v4.8).

### UX adopted-from-Mike (mirror but improve)

- Pop-over inline edit column with `{Label, Format dropdown, Prompt textarea + Auto-generate, Save, Delete}` — identical
- Pill colorato per format `enum`/`tag` — identical UX, our palette deterministic-by-value-hash
- Dot indicator on right edge of cell for flag color — adopt, but ALSO add background tint that scales with confidence score
- Badge `¹` citation count near content — adopt
- Sticky first column "Document" — adopt
- "+ Add Documents" / "+ Add Columns" top-right buttons — adopt
- "Assistant in Tabular Review" toggle (chat scoped) — adopt, but extend with cell-level scoping (diff #6)

## 4 — API surface

```
GET    /api/admin/tabular-reviews                     # list
POST   /api/admin/tabular-reviews                     # create
GET    /api/admin/tabular-reviews/:id                 # detail + cells
PATCH  /api/admin/tabular-reviews/:id                 # update title/columns/shared_with
DELETE /api/admin/tabular-reviews/:id                 # owner only
POST   /api/admin/tabular-reviews/:id/generate        # SSE — batch extract pending cells
POST   /api/admin/tabular-reviews/:id/regenerate-cell # body: {document_id, column_index}
POST   /api/admin/tabular-reviews/:id/clear-cells     # reset to pending
POST   /api/admin/tabular-reviews/prompt              # AI auto-generate a column prompt from name

GET    /api/admin/workflows                           # list (own + shared - hidden)
POST   /api/admin/workflows
GET    /api/admin/workflows/:id
PATCH  /api/admin/workflows/:id                       # requires edit permission
DELETE /api/admin/workflows/:id                       # owner only
POST   /api/admin/workflows/:id/share                 # body: {user_id, allow_edit}
DELETE /api/admin/workflows/:id/shares/:share_id
POST   /api/admin/workflows/hidden                    # body: {workflow_id}
DELETE /api/admin/workflows/hidden/:workflow_id
POST   /api/admin/workflows/suggest                   # NEW vs Mike: AI proposes 5 workflows
POST   /api/admin/workflows/from-suggestion           # body: {proposal_id} — save AI suggestion
```

All routes behind `can:viewTabularReview` / `can:editWorkflows` Spatie Gates.

## 5 — Frontend (React + shadcn + TanStack + Glide Data Grid)

```
frontend/src/routes/admin/tabular-reviews/
├── index.tsx                 # list page — TanStack Table
├── new.tsx                   # create modal
├── $id.tsx                   # detail — GLIDE DATA GRID with custom flag-cell renderer

frontend/src/routes/admin/workflows/
├── index.tsx                 # list with categories (own / shared / built-in) — TanStack Table
├── $id.tsx                   # editor (prompt + columns_config visual builder)
├── suggestions.tsx           # AI-Suggest gallery
```

### Grid library decision: `@glideapps/glide-data-grid` (MIT, ~50 KB gzipped)

For the **Tabular Review detail page** (the only spreadsheet-like surface), we use `@glideapps/glide-data-grid` — canvas-based, the same library that powers Glide's own apps + Notion-style table views. Why over alternatives:

| Criterion | Glide Data Grid | TanStack Table + react-virtual | AG Grid Community |
|---|---|---|---|
| Render | Canvas — 100K+ rows fluid | DOM — 5K rows ok, slows beyond | DOM with virtualisation |
| Custom cell render | `provideEditor` + `drawCustomCell` perfect for flag+citation popover | Standard React `cell: ({row}) => ...` | React renderers |
| Streaming cell updates | `gridRef.current.updateCells([...])` atomic per-cell repaint, no full re-render | Mutate state + re-key — risk of stale paint | `setValue()` API |
| Selection / copy-paste / resize / reorder | All built-in | Build from scratch | Built-in |
| License | MIT | MIT | MIT (Enterprise = paid) |
| Bundle (gz) | ~50 KB | ~20 KB headless + you write everything | ~150 KB |
| Theming with Tailwind v4 + tokens.css | `theme` prop maps every token | Your own classes | CSS variables |
| UX feel | Excel/Notion premium | Admin table normal | Excel/SAP enterprise |

**Mike (the inspiration)** rolled its own grid with `div` + flexbox + manual sticky + NO virtual scrolling — the 500-row barrier is a real perf cliff for them. We avoid it on day 1.

Other admin pages (`workflows list`, `users`, `connectors`) **keep TanStack Table** + shadcn DataTable patterns — consistency, no need for canvas overhead on small tables.

### Glide wiring sketch

```tsx
import { DataEditor, GridCellKind, type Item, type GridCell } from '@glideapps/glide-data-grid';
import '@glideapps/glide-data-grid/dist/index.css';

const getCellContent = useCallback(([col, row]: Item): GridCell => {
  if (col === 0) return { kind: GridCellKind.Text, data: docs[row].title, ... };
  const cell = cellsMap.get(`${row}:${col - 1}`);
  if (!cell || cell.status === 'generating') return { kind: GridCellKind.Loading, allowOverlay: false };
  return {
    kind: GridCellKind.Custom,
    data: { content: cell.content.summary, flag: cell.flag, citations: cell.content.citations },
    copyData: cell.content.summary,
    themeOverride: { bgCell: FLAG_BG[cell.flag] },
    allowOverlay: true,
  };
}, [cellsMap, docs]);

useEffect(() => {
  const sse = new EventSource(`/api/admin/tabular-reviews/${id}/generate`);
  sse.addEventListener('cell', (e) => {
    const c = JSON.parse(e.data);
    cellsMap.set(`${c.row}:${c.col}`, c);
    gridRef.current?.updateCells([{ cell: [c.col + 1, c.row] }]);  // ATOMIC repaint
  });
  return () => sse.close();
}, [id]);

const flagCellRenderer: CustomRenderer<FlagCell> = {
  kind: GridCellKind.Custom,
  isMatch: (cell): cell is FlagCell => 'flag' in cell.data,
  draw: ({ ctx, theme, rect }, cell) => {
    ctx.fillStyle = FLAG_BG[cell.data.flag];
    ctx.fillRect(rect.x, rect.y, rect.width, rect.height);
    ctx.fillStyle = theme.textDark;
    ctx.fillText(truncate(cell.data.content, 80), rect.x + 8, rect.y + 20);
    if (cell.data.citations.length > 0) drawBadge(ctx, rect.x + rect.width - 24, rect.y + 4, cell.data.citations.length);
    return true;
  },
  provideEditor: () => ({ editor: CitationPopover }),
};
```

### Export

In-browser CSV/JSON export iterates `getCellContent` and dumps via Blob — no backend round-trip. For Excel (.xlsx) we add `exceljs` (MIT, ~150 KB) — Mike already uses it; we wire it lazy-loaded.

Streaming SSE handled via Vercel AI SDK helpers (pattern already in place since v4.0 W3 chat migration). Per-cell SSE event payload `{ row, col, content, flag, citations[] }`.

## 6 — Test coverage targets

| Surface | Tests |
|---|---|
| TabularReviewExtractor — batch + streaming + frontmatter shortcut + R14 refusal | ~25 PHPUnit |
| TabularReviewService CRUD + tenant isolation + share ACL | ~10 PHPUnit |
| ColumnFormatRegistry — all 9 format types | ~10 PHPUnit |
| CitationFormatter parse + format | ~5 PHPUnit |
| FlagClassifier rules | ~6 PHPUnit |
| WorkflowService CRUD + sharing + hide | ~12 PHPUnit |
| WorkflowSuggester — happy path + low-data tenant fallback + LLM error path | ~8 PHPUnit |
| MetadataPatternAnalyzer | ~6 PHPUnit |
| BuiltinWorkflowSeeder — 15 templates valid + idempotent | ~3 PHPUnit |
| Admin SPA cells + flag + popover citation | ~15 Vitest |
| Workflow editor + suggester UI | ~10 Vitest |
| Playwright happy path (create review → generate cells → click citation → opens doc) | ~6 scenarios |
| Playwright failure path (no evidence → red flag surfaced; LLM 429 → row marked error) | ~3 scenarios |

**Target total**: ~120 new tests.

## 7 — Sequencing within v4.7

| Wn | Scope |
|---|---|
| **W1** | Tabular Review backend (schema + service + extractor + streaming + API) + ~50 tests |
| **W2** | Workflows backend (schema + service + WorkflowSuggester + 15 built-in templates + API) + ~35 tests |
| **W3** | Admin SPA (both pages + streaming SSE + citation popover + AI-suggest gallery) + Playwright E2E + RC + GA tag v4.7.0 |

## 8 — Acceptance gates for v4.7.0 GA

- [ ] 4 new tables with tenant_id, GIN indexes on `columns_config` + `metadata`
- [ ] 9 format types supported, prompt suffixes injected, output validated
- [ ] Streaming SSE: ~200 docs × 5 columns in <30 sec end-to-end on dev fixtures
- [ ] Citations clickable, scroll-to-chunk in source doc viewer
- [ ] WorkflowSuggester returns 5 valid proposals from a stratified KB sample
- [ ] 15 built-in workflows seeded, idempotent on re-seed
- [ ] R36 Copilot loop green
- [ ] Playwright matrix happy + failure paths green
- [ ] ADR `docs/adr/0010-v47-tabular-review-and-workflows.md` shipped
- [ ] README refresh — add "Tabular Review" + "Workflows" rows to feature table
- [ ] RC tag `v4.7.0-rcN` at each Wn closure SHA per R39

## 9 — Out of scope (parked for v4.8+)

- Cross-tenant workflow marketplace (community-published workflows)
- Workflow execution scheduling (cron-style "Run this every Monday")
- Workflow versioning (track prompt edits over time)
- Tabular review export to xlsx — defer to v4.8 (basic CSV/JSON export ships in v4.7)
- Cell-level diffing across review regenerations
- Approval/sign-off workflow on critical reviews (compliance-grade)

## 10 — v5.0 implications

Once v4.7 ships, the v5.0 agentic platform gets two native building blocks:
- **MCP tool `workflow.run(workflow_id, document_ids)`** — agents invoke saved workflows as tools.
- **MCP tool `tabular.extract(columns, document_ids)`** — agents create on-the-fly structured reviews mid-conversation.

`WorkflowSuggester`'s `MetadataPatternAnalyzer` also becomes useful as an agent's self-discovery primitive ("what kinds of questions can this tenant's KB answer best?").
