# DESIGN v4.5/W5.5 — Source-aware ingestion + chunking + retrieval

**Author:** Lorenzo Padovani + Claude (assisted)
**Date:** 2026-05-11
**Status:** Locked-in (Lorenzo: "opzione A" mid-v4.5/W5)
**Scope:** Sub-PR `enh/v4.5/w5.5-source-aware-ingestion` — to be merged BEFORE W6 (Jira + Vercel SDK UI Tier 1).

## 1 — Why this exists

v4.5/W1-W5 shipped six connectors (Google Drive, Notion, Evernote, Fabric, OneDrive, Confluence). Every one of them **degrades source content to markdown** and routes through the existing pipeline:

```
<Source>Connector → <Source>ToMarkdown → write .md (+ minimal frontmatter) → IngestDocumentJob
                                                                              ↓
                                                                      DocumentIngestor::ingestMarkdown
                                                                              ↓
                                                                      MarkdownChunker (section-aware H1/H2/H3)
                                                                              ↓
                                                                      EmbedChunks → knowledge_chunks
```

Pragmatic, fast — but **loses the structured signals that make these knowledge bases valuable**:

| Source | What's dropped today |
|---|---|
| **Notion** | page properties (status, tags, owner, dates, relations, formulas, rollups), block hierarchy, database row schema, comments |
| **Google Drive** | folder path, owner, shared-with, revisions, native mime (gdoc/gsheet/gslide structure) |
| **OneDrive** | folder path, last-modified-by, sharing context, Office structure |
| **Evernote** | tags, notebook, reminders, geolocation, original source URL |
| **Confluence** | space key, page hierarchy, labels, restrictions, mentions, version |
| **Fabric** | tags, collections, AI annotations |

A "Project Status: Done" Notion property — invisible to the retriever today. A Confluence page in space `ENGINEERING` labeled `architecture-decision` — the reranker can't boost it. Evernote tagged `critical` — same.

**Standing rule** (memory `feedback_ingestion_per_source_chunker_rule.md`, 2026-05-11): every new ingestion source MUST get an ad-hoc chunker + rich frontmatter + retrieval boost policy. W5.5 retroactively applies the rule to the six connectors shipped in W1-W5.

## 2 — The four layers

```
              ┌──────────────────────────────────────────────────────┐
   Layer 1    │  Rich frontmatter capture (per-connector)             │
              │  e.g. notion: { database_id, properties.* }            │
              └──────────────────────────────────────────────────────┘
                                  ↓
              ┌──────────────────────────────────────────────────────┐
   Layer 2    │  ChunkerRegistry::resolve($source_type)               │
              │  → MarkdownChunker | NotionBlockChunker |              │
              │    ConfluencePageChunker | OfficeDocChunker |          │
              │    AtomicNoteChunker | PdfPageChunker                  │
              └──────────────────────────────────────────────────────┘
                                  ↓
              ┌──────────────────────────────────────────────────────┐
   Layer 3    │  Chunk metadata enrichment                            │
              │  knowledge_chunks.metadata gets:                       │
              │    source_type, tags[], status, space, owner, recency  │
              └──────────────────────────────────────────────────────┘
                                  ↓
              ┌──────────────────────────────────────────────────────┐
   Layer 4    │  Retrieval boost policy                               │
              │  Reranker reads metadata, boosts on:                   │
              │    tag overlap, recency, status, source-type weight    │
              └──────────────────────────────────────────────────────┘
```

### Layer 1 — Rich frontmatter capture

**Where**: inside each connector's `ingestPage()` / `ingestFile()` private method, between source-content fetch and the `Storage::put($mdPath, $content)` call. Build a richer YAML frontmatter that captures the source's structured properties under a namespaced key.

**Frontmatter schema** (extended from v4.0 canonical-aware shape):

```yaml
---
# v4.0 canonical fields (existing)
project_key: "engineering"
title: "Cache eviction policy"
source: "notion"
source_id: "8e1b3-..."
source_url: "https://www.notion.so/..."
last_modified: "2026-05-11T15:30:00Z"

# v4.5/W5.5 source-namespaced metadata
notion:
  database_id: "abc123"
  properties:
    status: "In Progress"
    tags: ["decision", "architecture"]
    owner: "lorenzo@padosoft.com"
    priority: "P0"
  last_edited_by: "ai-agent@padosoft.com"

# v4.5/W5.5 derived signals (computed by the connector, queryable)
_derived:
  search_tags: ["decision", "architecture"]
  recency_bucket: "this_week"
  status_active: true
---
```

The `_derived` sub-map is **always present**, **always queryable** — the chunker propagates it to `knowledge_chunks.metadata`. Different sources fill the `<source>:` namespace differently; `_derived` normalizes signals across all sources.

### Layer 2 — `ChunkerRegistry::resolve($source_type)`

**New service**, registered as a singleton in `AppServiceProvider`. Loads chunker FQCNs from `config('chunkers.registry')`. R23-compliant (boot-time FQCN validation; first-match-wins resolution on `supports()` predicates).

```php
interface ChunkerInterface
{
    public function supports(string $sourceType, array $frontmatter): bool;
    public function chunk(string $markdown, array $frontmatter): array;  // returns Chunk[]
}

final class ChunkerRegistry
{
    public function resolve(string $sourceType, array $frontmatter = []): ChunkerInterface
    {
        foreach ($this->chunkers as $chunker) {
            if ($chunker->supports($sourceType, $frontmatter)) {
                return $chunker;
            }
        }
        return $this->fallback;  // MarkdownChunker
    }
}
```

**Six chunkers ship in W5.5:**

| Chunker | Source types | Strategy |
|---|---|---|
| `MarkdownChunker` (existing, **default fallback**) | `manual_upload`, `gh_push`, unknown | Section-aware H1/H2/H3, fence-aware FSM, ~500-1000 tokens with overlap |
| `NotionBlockChunker` (NEW) | `notion` | Respects block boundaries (paragraph/heading/list/toggle/quote/callout). Each top-level block is a chunk seed. Aggregates short adjacent blocks until ~500 tokens. Property panel appears as a synthetic preamble chunk. |
| `ConfluencePageChunker` (NEW) | `confluence` | Respects page hierarchy (H1-H4 from converted storage HTML). Skips `<ac:structured-macro>` non-textual content (jira-issues macro, etc.). Page properties → preamble chunk. |
| `OfficeDocChunker` (NEW) | `drive_gdoc`, `drive_gsheet`, `drive_gslide`, `onedrive_office` | Per-format strategies: gdoc → markdown sections; gsheet → row-window chunks (10 rows per chunk + header context); gslide → slide-per-chunk. **Stretch**: defer gslide to W5.5+1 if scope tight. |
| `AtomicNoteChunker` (NEW) | `evernote`, `fabric`, `notion` (when note < threshold) | One note = one chunk if < 800 tokens. Otherwise H2-based section split. Tags/notebook propagated to chunk metadata. |
| `PdfPageChunker` (existing, T1.x) | `drive_pdf`, `onedrive_pdf`, `confluence_attachment` (when PDF) | Per-page chunk, OCR fallback. Already shipped pre-v4.5 — just needs registry wiring. |

**Routing examples:**
```
NotionConnector writes: { source: "notion" } → ChunkerRegistry → NotionBlockChunker
ConfluenceConnector writes: { source: "confluence" } → ConfluencePageChunker
EvernoteConnector writes: { source: "evernote" } → AtomicNoteChunker
GoogleDriveConnector writes Gdoc: { source: "drive_gdoc" } → OfficeDocChunker (gdoc strategy)
GoogleDriveConnector writes PDF: { source: "drive_pdf" } → PdfPageChunker
GoogleDriveConnector writes .md: { source: "drive_md" } → MarkdownChunker
```

The `source` field in frontmatter is **a contract** — connectors set it, registry dispatches on it.

### Layer 3 — Chunk metadata enrichment

`knowledge_chunks.metadata` (already a JSON column) carries the chunk-time enrichment. **New convention** (set by every chunker):

```json
{
    "source_type": "notion",
    "search_tags": ["decision", "architecture"],
    "status_active": true,
    "owner": "lorenzo@padosoft.com",
    "recency_bucket": "this_week",
    "page_block_path": "Block 3 → Toggle 'Cache policy'",
    "page_property_panel": false
}
```

`page_property_panel: true` marks the synthetic preamble chunk (Notion property block, Confluence page-properties macro). The reranker can boost preamble chunks when the query asks about properties (e.g. "what's the status of X?").

### Layer 4 — Retrieval boost policy

`Reranker::rerank()` already scores `0.6·vec + 0.3·kw + 0.1·head`. **Extend with two new signals**:

| Signal | Weight | Source |
|---|---|---|
| `tag_overlap` | +0.05 | jaccard(`query_tags`, `chunk.search_tags`) — query tags extracted via simple TF-IDF on first 5 turn messages |
| `recency_score` | +0.02 | `now() - chunk.last_modified` mapped to `{this_week: 1.0, this_month: 0.7, this_quarter: 0.4, older: 0.1}` |
| `status_active` | +0.02 | chunks where `metadata.status_active == true` |
| `preamble_match` | +0.05 | when query starts with "what's the status / who owns / when was" → boost `page_property_panel = true` |

New formula: `0.55·vec + 0.25·kw + 0.05·head + 0.05·tag_overlap + 0.05·preamble_match + 0.02·recency + 0.02·status + 0.01·canonical_boost`. Sum = 1.0. Weights configurable via `config('kb.reranker.weights')`.

**`KbSearchService` gains optional facets** for the SPA filter bar (W6 admin UI scope):
```
GET /api/kb/search?q=cache+policy&facets[source]=notion,confluence&facets[tag]=decision
```
The facets filter at SQL level (`WHERE metadata->>'source_type' IN (...)` and `metadata->'search_tags' ?& ARRAY[...]`), before reranking. Saves embed-token spend on irrelevant chunks.

## 3 — Schema impact

**No new tables**. Two existing columns gain semantic:

- `knowledge_documents.frontmatter_json` already exists (v4.0 canonical). Connectors populate the new `<source>:` namespace under it.
- `knowledge_chunks.metadata` (JSON) — already exists. The convention in §Layer 3 is the new contract.

**Indexes** (new in this PR):
```sql
-- pgsql only (sqlite shims as no-op via existing migration pattern)
CREATE INDEX idx_kb_chunks_source_type ON knowledge_chunks USING gin ((metadata->'source_type'));
CREATE INDEX idx_kb_chunks_search_tags ON knowledge_chunks USING gin ((metadata->'search_tags'));
CREATE INDEX idx_kb_chunks_recency ON knowledge_chunks ((metadata->>'recency_bucket'));
```

## 4 — Files to add (W5.5 sub-PR)

```
app/Services/Kb/Chunking/
├── ChunkerInterface.php                      # contract
├── ChunkerRegistry.php                       # R23 boot-time FQCN validation
├── MarkdownChunker.php                       # existing, moved + wired into registry
├── NotionBlockChunker.php                    # NEW
├── ConfluencePageChunker.php                 # NEW
├── OfficeDocChunker.php                      # NEW
├── AtomicNoteChunker.php                     # NEW
└── PdfPageChunker.php                        # existing, wired

app/Services/Kb/Chunking/Support/
├── BlockTreeWalker.php                       # walks Notion-shaped block trees
├── PageHierarchyExtractor.php                # extracts H1-H4 structure from converted MD
├── TokenCounter.php                          # cheap whitespace-based estimator (no tiktoken)
└── RecencyBucketer.php                       # last_modified → "this_week" etc.

app/Services/Kb/Retrieval/
├── TagOverlapScorer.php                      # jaccard on chunk.search_tags vs query_tags
├── QueryTagExtractor.php                     # TF-IDF on query + recent turn context
├── RecencyScorer.php                         # bucket → score
└── PreambleMatchDetector.php                 # "what's the status / who owns" patterns

app/Services/Kb/Reranker.php (MODIFIED)       # adds Layer-4 signals

config/chunkers.php (NEW)                     # registry config
config/kb.php (MODIFIED)                      # reranker.weights extension

database/migrations/
├── 2026_05_11_add_kb_chunks_source_indexes.php (NEW)

app/Connectors/BuiltIn/*Connector.php (6 files MODIFIED)
  - GoogleDriveConnector.php
  - NotionConnector.php
  - EvernoteConnector.php
  - FabricConnector.php
  - OneDriveConnector.php
  - ConfluenceConnector.php
  - each writes richer frontmatter per the §Layer 1 schema

tests/Feature/Kb/Chunking/                    # ~80 new tests
  - Per-chunker happy/edge/boundary
  - Registry FQCN validation + supports() mutex (R23)
  - Frontmatter → chunk.metadata propagation

tests/Feature/Kb/Retrieval/                   # ~20 new tests
  - TagOverlapScorer jaccard math
  - QueryTagExtractor extraction
  - Reranker weighted-sum formula
  - PreambleMatchDetector pattern coverage

tests/Feature/Connectors/<Source>FrontmatterCaptureTest.php (6 NEW files, 1 per connector)
  - Asserts every connector populates the right namespaced fields + the _derived sub-map
```

## 5 — Migration story for existing chunks

The 6 connectors shipped in W1-W5 wrote chunks with **minimal frontmatter** + `MarkdownChunker` boundaries. They're not wrong — they're just under-enriched.

**Strategy: idempotent re-ingest via existing pipeline**. Every connector already supports a `syncFull()` operator-triggered rebuild. After W5.5 lands:

1. Operators trigger `syncFull()` from the connector admin UI (W3).
2. The new ingestion writes rich frontmatter + dispatches `IngestDocumentJob`.
3. The job calls `DocumentIngestor::ingestMarkdown()`, which respects the existing `(project_key, source_path, version_hash)` idempotency anchor.
4. `version_hash` changes because frontmatter content changes → previous chunks are archived, new ones written via the source-aware chunker.

**No data migration script needed**. The hash-based idempotency does the right thing.

## 6 — Acceptance gates

Mandatory for the W5.5 sub-PR to merge:

- [ ] Six per-source chunkers + registry shipped, all `ChunkerInterface`
- [ ] `ChunkerRegistry` R23-compliant — FQCN validation + `supports()` non-overlap test
- [ ] Six connectors write rich namespaced frontmatter + `_derived` map
- [ ] Reranker extended with 4 new signals, weights config-driven
- [ ] `KbSearchService` accepts `facets[source]` + `facets[tag]`
- [ ] +80 PHPUnit tests (chunkers + retrieval + connector frontmatter)
- [ ] Existing 272 connector tests still green (W1-W5 baseline preserved)
- [ ] Existing 1700+ AskMyDocs test count holds (no retrieval-path regression)
- [ ] `php artisan kb:rebuild-graph` no-op on rich-frontmatter docs (graph layer doesn't care about new fields)
- [ ] CI matrix PHP 8.3/8.4/8.5 + Vitest + Playwright + RAG regression all green
- [ ] R36 Copilot review loop until 0 outstanding must-fix

## 7 — Out of scope (parked for v4.6+)

- **gslide chunker** — slides have visual structure; defer until we have a slide-extraction story (PDF render → image OCR fallback)
- **Notion database row schema indexing** — treating Notion DBs as structured records (separate index, queryable as tables) is a v5.0 agentic primitive, not a chunker concern
- **Confluence comments + attachments** — comments become a separate doc shape (small note-style); image/pdf attachments fan out to existing pdf/image pipelines. v4.6 W1.
- **Cross-source dedup** — same content uploaded to Drive + Confluence + Notion produces 3 chunks today; dedup is a v4.7 retrieval-layer concern (LSH on chunk hashes).

## 8 — Risk register

| Risk | Mitigation |
|---|---|
| Chunker overlap predicate ambiguity — first-match-wins bug | R23 mutex test asserts no two `supports()` overlap on the same `(source, frontmatter)` pair |
| Reranker weight tuning regression — new formula scores differently | Existing RAG regression gate (`tests/Eval/golden/rag-baseline-*.yml`) catches retrieval drift; if it fails, weights revert |
| Frontmatter schema churn — connectors writing inconsistent shapes | Per-connector `FrontmatterCaptureTest` pins the contract per source |
| Index bloat from new GIN indexes on `metadata` | Limited to 3 indexes, all on JSON paths actually queried by the SPA facets; EXPLAIN ANALYZE on a 1M-chunk fixture before merge |

## 9 — Sequencing

1. **W5.5.1** — `ChunkerInterface` + `ChunkerRegistry` + migrate existing `MarkdownChunker` into the registry. **No new chunker logic yet.** Confirms the contract, mutex test, dispatch path.
2. **W5.5.2** — Five new chunkers (Notion / Confluence / Office / AtomicNote / Pdf wired). Per-chunker test coverage.
3. **W5.5.3** — Update six connectors to write rich frontmatter. `FrontmatterCaptureTest` per connector.
4. **W5.5.4** — Reranker Layer-4 signals + `KbSearchService` facets. RAG regression baseline refresh.
5. **W5.5.5** — Closure status doc + README refresh (add the source-aware story to the architecture diagram).

Each numbered step is one commit on the same branch `enh/v4.5/w5.5-source-aware-ingestion`. One sub-PR, atomic merge.

## 10 — After this PR merges

W6 (Jira + Vercel SDK UI Tier 1) resumes — but now with a clean architectural foundation. Every new connector (Jira in W6 is the next one) ships with a per-source chunker + frontmatter contract under the standing rule.
