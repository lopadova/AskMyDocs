# PLAN v8.41 → v8.45 — Retrieval quality

- **Status:** Proposed (2026-11-04)
- **Decision record:** [ADR 0035](../adr/0035-v841-retrieval-quality-cycle.md) —
  decisions D1–D7 referenced below.
- **Origin:** comparative study of `tobi/qmd` (`86d2365`, 2026-10-14). Our
  platform (tenancy, ACL in SQL, canonical graph, promotion, PII, FinOps,
  connectors, OCR, eval gate) is well ahead; qmd's ranking tail (cross-encoder,
  typed expansion, contextual embeddings, explain traces) is ahead of ours.
  This cycle closes that gap, plus the agent affordances and the code content
  class.

---

## 0. Why now, in four facts

1. **No model ever reads the query and the chunk together.** `Reranker` is a
   lexical/metadata heuristic (`0.55·vec + 0.25·kw + 0.05·head` + tags, recency,
   preamble, canonical boost/penalty, mentions). Paraphrase recall rests on the
   bi-encoder alone.
2. **Chunks are embedded naked.** `DocumentIngestor` embeds `ChunkDraft::$text`
   without the document title or `heading_path`, and nothing records which
   provider/model/template/chunking produced a vector.
3. **We cannot prove any of this.** `resources/benchmark/` is 5 documents and
   14 queries at nDCG@5 0.997 / MRR 1.000 — saturated. Every improvement would
   pass and none would be measured.
4. **Code is not ingestable.** `SourceType::fromExtension()` knows `md`,
   `markdown`, `txt`, `pdf`, `docx` and images only; no connector or the GitHub
   Action carries source files.

---

## 1. Workstreams

Effort key: **S** ≤ 1 day · **M** 2–4 days · **L** ≥ 1 week.

### W1 — Measure first: benchmark v2, `explain`, lexical syntax (M) — v8.41

ADR 0035 **D1** + **D3**.

**1.1 Benchmark v2** (`resources/benchmark/v2/corpus/`, `resources/benchmark/v2/queries.yaml`)

- ≥ 40 synthetic documents (md, PDF, DOCX; one canonical cluster with
  `kb_edges`; one `rejected-approach`; a small PHP + TypeScript tree reserved
  for W5, ingested only when `KB_CODE_INGESTION_ENABLED=true`).
- ≥ 90 labelled queries, composition per ADR 0035 §D1 table (paraphrase, hard
  negatives, identifiers/phrases/negation, context-dependent chunks, short
  ambiguous, IT+EN, code, refusals). Same YAML schema as v1 (`relevance`,
  `expect_citation`, `expect_related`, `expect_rejected`, `expect_refusal`)
  plus an optional `class:` tag so metrics can be reported **per class**.
- `kb:benchmark` gains `--suite=v1|v2` (default `v1`, back-compatible) and a
  per-class breakdown in its report; `--gate` thresholds for v2 set from the
  first baseline (baseline − 0.02) and committed.
- CI: v1 `--gate --stub` stays; v2 `--gate --stub` added to the same job.
  A LIVE run (real embeddings) is executed by hand at each W closure and its
  numbers go in the closure doc.

**1.2 `explain`** (D1)

- `RetrievalFilters::$explain` (additive, default `false`).
- `KbSearchService` collects per-list contributions during fusion and the
  heuristic components from `Reranker` (Reranker returns its component
  breakdown when asked — additive array keys, R27).
- `RetrievalExplainPresenter` shapes the trace; `SearchResult` carries it.
- Surfaces (R44): `kb:search {query} {--project=} {--tenant=default}
  {--limit=8} {--explain}` · `POST /api/admin/kb/retrieval/explain`
  (`role:admin|super-admin`, **R32 matrix row**) · `KbRetrievalExplainTool`
  (read-only, registered; the derived MCP roster test covers it automatically).
- Flag: `KB_RETRIEVAL_EXPLAIN_ENABLED` (default `false`) gates the HTTP and MCP
  surfaces (404 / tool-disabled response when off); the CLI always works for
  operators.

**1.3 Lexical syntax** (D3)

- `buildFtsTsquery()` → `websearch_to_tsquery` per phrase (synonym OR-expansion
  preserved), bare single term → prefix match, compound identifiers →
  `phraseto_tsquery`; `ts_rank_cd(…, 32)` for a bounded score.
- Term sanitisation to `[\p{L}\p{N}_]+` before it becomes a bound parameter;
  a test feeds `:*`, `&`, `|`, `!`, `(`, quotes and backslashes and asserts no
  SQL error and no injection (SEC-RAWSQL-001).
- Flag `KB_FTS_QUERY_SYNTAX=plain|websearch` (default `plain`).
- **Decision gate at W1 closure:** promote `websearch` and/or
  `KB_HYBRID_SEARCH_ENABLED=true` to default only with benchmark v2 evidence
  (nDCG@5 and MRR ≥ vector-only baseline, refusal accuracy unchanged).

**Tests:** explain OFF ⇒ no trace key, no extra queries (query-count assertion);
explain ON ⇒ contributions sum to the RRF total; R32 matrix row; MCP tool
contract; FTS syntax cases on pgsql (skipped on SQLite with an explicit
reason) + the sanitiser unit-tested on both.

**Doc-site (R45):** `architecture/retrieval-pipeline.mdx` gains "Explaining a
ranking" + "Lexical query syntax"; `reference/cli.mdx` and `reference/api.mdx`
rows; `mcp-server.mdx` tool row.

---

### W2 — Contextual embeddings + fingerprint (M) — v8.42

ADR 0035 **D2**.

- `App\Services\Kb\Embedding\EmbeddingInputTemplate` — `forChunk(doc, draft)`,
  `version()`, `fingerprint()` (sha256 of provider | model | dimensions |
  template_version | target_tokens | overlap_tokens | hard_cap_tokens, 16 hex).
- `DocumentIngestor` and the Flow `ChunkDocumentStep` both compose through it —
  **one** composition path (a test asserts both ingestion entry points produce
  identical embedding inputs).
- The **composed** string goes through `ChunkRedactor` before
  `EmbeddingCacheService::generate()`; `chunk_text` persisted stays bare.
- Migration: `knowledge_chunks.embedding_fingerprint` string(16) nullable +
  index `(tenant_id, embedding_fingerprint)`. Tenant-aware table already
  (R31); SQLite test migration mirrored under `tests/database/migrations/`.
- `kb:reembed-stale {--project=} {--tenant=default} {--batch=100} {--dry-run}`
  — `chunkById(100)`, queued, idempotent, resumable; dry run prints count and
  estimated cost from the FinOps price table.
- Ingestion status (artisan / HTTP / `KbIngestionStatusTool`): additive key
  `stale_embeddings` per project.
- Flag `KB_EMBEDDING_CONTEXTUAL_INPUT` (default `false`).

**Tests:** flag OFF ⇒ inputs byte-identical to today (golden test on a fixed
document); ON ⇒ template applied, fingerprint written; PII in title never
reaches the embeddings fake when the redactor is active; re-embed selects
exactly the stale set across two tenants without crossing them (R30);
`--dry-run` writes nothing.

**Benchmark:** LIVE v2 run with ON after `kb:reembed-stale` on the benchmark
project; context-dependent class must improve, no class may regress beyond
tolerance. Default flips to ON only on that evidence.

**Doc-site:** `architecture/ingestion-pipeline.mdx` "What is embedded, and
why" + "Embedding fingerprint and re-embedding"; `configuration.mdx` rows.

---

### W3 — Cross-encoder stage (M/L) — v8.43

ADR 0035 **D4**.

- Contract `CrossEncoderReranker::score(string $query, list<string> $passages): list<float>`;
  drivers `http` (exact host allow-list, timeout, body cap, closed response
  validation) and `fake` (tests/benchmark only; refused by the production
  posture check).
- `CrossEncoderStage` after `Reranker` (which is then asked for
  `kb.cross_rerank.candidates`, default 30), position-aware blend with tiers
  0.75 / 0.60 / 0.40 over the **normalised heuristic score**, status penalty
  applied to the cross-encoder component, mention boost re-added after the
  blend, final slice to `limit`. Refusal floor untouched.
- PII policy applied to the query before egress; provider/host/model from the
  code-owned allow-list.
- `CrossRerankCallMeter` (purpose tag `cross_rerank`, `OcrCallMeter`
  precedent); cache in the Laravel cache store keyed
  `sha256(tenant | model | sha256(query) | chunk_hash)`, score only.
- Failure ⇒ heuristic order + `meta.cross_encoder.status = unavailable` +
  structured warning with correlation id.
- `explain` gains the `cross_encoder` block.
- Config `kb.cross_rerank.*`: `enabled` (`KB_CROSS_RERANK_ENABLED`, default
  `false`), `driver`, `endpoint`, `allowed_hosts`, `model`, `candidates`,
  `timeout`, `tiers`, `cache_ttl`.
- Tri-surface: the capability is "search", already tri-surface; its new state is
  visible through `explain` (W1) and its health through `HealthCheckService`
  (admin health) — no fourth surface (documented).

**Tests:** OFF ⇒ `shouldNotReceive('score')` and Reranker called with the
original `limit` (R26); ON ⇒ blend arithmetic unit-tested (head protection: a
rank-1 chunk with `c = 0` stays in top 3; a deprecated doc with `c = 1` cannot
pass an accepted one of equal heuristic score); malformed/wrong-count/NaN/
oversized responses ⇒ fallback; host not in allow-list ⇒ refused before
egress; cache hit ⇒ no HTTP call; metering row written; cross-tenant cache
keys never collide.

**Benchmark:** LIVE v2 with a real cross-encoder; paraphrase and hard-negative
classes must improve; p50/p95 latency of the stage reported. Default stays
OFF (it needs a provider contract per deployment) — the closure doc states the
measured gain so operators can decide.

**Doc-site:** `architecture/retrieval-pipeline.mdx` "Cross-encoder stage" with
a Mermaid diagram of the blend; `ai-providers.mdx` "Rerank providers";
`ai-finops.mdx` purpose tag row.

---

### W4 — Typed expansion + agent affordances (M) — v8.44

ADR 0035 **D5** + **D6**.

**4.1 Expansion** (D5)

- `QueryExpansionService` — one no-tools `AiManager` chat call (SDK path,
  metered by the FinOps hook), fixed system prompt, closed JSON schema
  (`lex` ≤ 3, `vec` ≤ 3, `hyde` ≤ 1 × 400 chars), discard on any violation,
  drift guard (shared significant term), duplicates of the original dropped.
- Routing: `lex` → full-text branch; `vec` + `hyde` → one batched embeddings
  call → vector branch.
- `reciprocalRankFusion()` generalised to N weighted lists (original × 2.0,
  expansions × 1.0, top-rank bonus).
- Strong-signal skip (bounded lexical score, or top-1 cosine when hybrid is
  off), thresholds `KB_QUERY_EXPANSION_SKIP_MIN_SCORE` /
  `KB_QUERY_EXPANSION_SKIP_MIN_GAP` calibrated on v2; disabled when `intent`
  is present.
- `intent` parameter on `KbSearchTool`, `KbSearchByProjectTool`, the chat API
  request (optional, additive) and `kb:search --intent=`; routed to the
  cross-encoder and best-chunk selection only.
- Cache keyed `sha256(tenant | project | model | query)`, dud eviction.
- Flag `KB_QUERY_EXPANSION_ENABLED` (default `false`).

**Tests:** OFF ⇒ `shouldNotReceive('chat')`; skip path ⇒ `shouldNotReceive`;
schema violations ⇒ discarded, search still answers; prompt-injection query
(`"ignore previous instructions…"`) yields either a valid expansion or none,
never a changed system prompt; dud eviction; N-list RRF weights independent of
insertion order.

**4.2 Agent affordances** (D6)

- **Facets:** `KbFacetsService`; `kb:facets` · `GET /api/kb/facets`
  (authenticated; R32 row) · `KbFacetsTool`. Flag `KB_MCP_FACETS_ENABLED`.
- **Line ranges:** `KbReadDocumentTool` `from_line` / `max_lines` (additive);
  preview endpoint query params; `kb:show {document} {--from=} {--lines=}`;
  `start_line` / `end_line` recorded in chunk metadata by `MarkdownChunker`
  (new writes; existing chunks gain them on re-ingest) and returned with
  citations.
- **Did you mean:** bounded Levenshtein suggestions on misses in
  `KbDocumentBySlugTool`, the read tools and the HTTP 404 bodies — computed
  only over documents the caller can read.
- **Path contexts:** migration `kb_path_contexts` (tenant-aware, UNIQUE
  `(tenant_id, project_key, path_prefix)`); `PathContextService`
  (longest-prefix chain); prompt block rendered as delimited untrusted data
  above `## Context` in `kb_rag.blade.php`; citations carry `path_context`.
  Surfaces: `kb:path-context {add|list|remove}` · `GET|PUT|DELETE
  /api/admin/kb/path-contexts` (R32 row; mutations audited in
  `admin_command_audit`) · `KbPathContextsTool` (read-only; write kept off MCP,
  documented R44 exception). Flag `KB_PATH_CONTEXTS_ENABLED`.
- **Admin UI:** a "Path contexts" tab in the KB admin area
  (`frontend/src/features/admin/kb/…`), R11 test ids, R15 a11y, Vitest +
  Playwright real-data spec (R12/R13).

**Tests:** cross-tenant and ACL-hidden documents never appear in facets,
suggestions or path-context chains (tested through the relationship, R33);
prompt rendering escapes delimiter collisions; R43 both states for each flag;
R32 matrix rows.

**Doc-site:** `mcp-server.mdx` (facets, line ranges, did-you-mean, path
contexts); `chat-and-retrieval.mdx` "Query expansion" + "Intent";
`admin-panel.mdx` "Path contexts".

---

### W5 — Source code as a content class (L) — v8.45

ADR 0035 **D7**.

**5.0 Spike (S, first, gates the rest of W5)**

- Confirm pinned `web-tree-sitter` + prebuilt `.wasm` grammars for PHP,
  TypeScript/TSX, JavaScript, Python, Go; measure parse time and memory on a
  2 000-file repository; decide the helper's packaging (in-repo
  `tools/code-chunker/` with its own `package.json`, built in CI).
- Output: a one-page note appended to ADR 0035 (grammar list actually shipped,
  measured budgets). A grammar that cannot be pinned is moved to the heuristic
  driver, not dropped.

**5.1 Content class and entry points**

- `SourceType::CODE`; extension → language map; `text/x-<lang>` tokens in
  `config/kb-pipeline.php` `mime_to_source_type`; acceptance gated by
  `KB_CODE_INGESTION_ENABLED` at `kb:ingest-folder`, `POST /api/kb/ingest`
  and the GitHub Action (new input `code-globs`, default empty).
- `CodePassthroughConverter` (fenced block, `language` in `extractionMeta`).
- Non-overridable path deny-list, `KB_CODE_MAX_FILE_BYTES` (512 KB), generated
  file detection.
- `SecretRedactor` at the `ChunkRedactor` seam (`[REDACTED_SECRET]`), counts in
  ingestion status, never values in logs.

**5.2 Chunker**

- `tools/code-chunker/` Node helper: stdin source + language → JSON break
  points; no network, no filesystem writes, exit codes documented.
- `TreeSitterBreakPointDriver` (Symfony `Process`, argument array, timeout
  `KB_CODE_PARSE_TIMEOUT`, output cap, health probe) and
  `HeuristicBreakPointDriver` (pure PHP line patterns per language).
- `CodeAstChunker` (`supports('code')`; mutex test extended): scored break
  points, look-back window with squared-distance decay, never splits a node
  below the hard cap; `heading_path` = symbol path; metadata `language`,
  `symbol`, `start_line`, `end_line`, `chunker`.
- `KB_CODE_CHUNKER_DRIVER=auto|tree_sitter|heuristic` (default `auto`);
  forced `tree_sitter` when unavailable ⇒ loud ingest failure (R14).
- Embedding template for code (D2) — `file | symbol | lang | code`.
- Full-text: partial expression GIN index with the `simple` configuration for
  chunks with `metadata->>'language'` (pgsql only).

**5.3 Observability**

- `HealthCheckService`: helper + grammar availability.
- Ingestion status: per-document chunker used, secret-redaction count.

**Tests:** flag OFF ⇒ a `.php` upload is rejected exactly as today; ON ⇒
accepted on all three entry points; deny-listed paths and oversize files
skipped with a reason; secrets in fixtures never reach storage or the
embeddings fake; helper timeout / crash / malformed JSON ⇒ heuristic fallback
under `auto`, loud failure under `tree_sitter`; chunker idempotent (same input
⇒ same drafts, R23 contract); `Process` built from an array (architecture
test: no string command); mutex test; code queries in benchmark v2 pass.

**Doc-site:** new page `ingesting-source-code.mdx` (motivation, Mermaid of
helper ↔ PHP, deny-list, secrets, drivers, gotchas) registered in `docs.json`;
`ingestion.mdx` + `architecture/ingestion-pipeline.mdx` cross-links;
`self-hosting.mdx` "Node on ingestion workers (optional)".

---

## 2. Sequencing and dependencies

```
W1 benchmark v2 + explain + FTS syntax ──► every other W is measured against it
        │
        ├──► W2 contextual embeddings ──► W3 cross-encoder ──► W4 expansion + agent affordances
        │                                   (measured on the new vectors)   (expansion widens the
        │                                                                     candidate set; the
        │                                                                     cross-encoder tames it)
        └──► W5 code content class (needs W1's code queries and W2's template;
                                     independent of W3/W4 — may run in parallel with them)
```

Branching per R37 as in the previous cycle: one integration branch per release
(`feature/v8.41` … `feature/v8.45`) merged to `main` with the GA tag, one task
branch per workstream targeting it; RC tags per R39; review loop per R36/R40
(Copilot → Codex fallback → independent subagent review when both cloud
reviewers are unavailable); deferred E2E per R46.

---

## 3. Out of scope, on purpose

- Local model inference inside the app process.
- Fine-tuning an expansion model (needs W4's real query logs first).
- Using the cross-encoder score as the refusal signal (own calibration cycle).
- A GitHub/GitLab connector package and webhook incremental sync of
  repositories (W5 covers CLI, API and the GitHub Action).
- Symbol-level graph edges from code (imports/calls → `kb_edges`).

---

## 4. Acceptance — what "done" proves

1. `kb:benchmark --suite=v2 --gate` runs in CI; its per-class report is in every
   closure doc from W1 onward.
2. An operator can run `kb:search "…" --explain` and see, for each result, which
   lists ranked it, with what weight, and which heuristic and cross-encoder terms
   moved it — and the same trace through the admin API and MCP when the flag is on.
3. With W2 + W3 enabled on the benchmark project, nDCG@5 and MRR on v2 exceed the
   W1 baseline, the paraphrase / hard-negative / context-dependent classes
   improve, and refusal accuracy does not drop. Numbers recorded, LIVE.
4. With every new flag OFF, retrieval output on v1 and v2 is identical to the W1
   baseline (golden comparison).
5. An MCP agent can list the filter facets of its tenant, read lines 120–160 of a
   cited document, recover from a misspelt slug, and receive the path context of
   each citation — none of which exposes a document it could not retrieve.
6. A PHP + TypeScript repository ingested through the GitHub Action with
   `code-globs` is chunked per symbol, cited with line ranges, has a planted fake
   API key redacted before storage, and passes the code class of benchmark v2;
   with the Node helper absent, the same ingest succeeds on the heuristic driver.

---

## 5. Deliverables checklist (per workstream)

- [ ] ADR 0035 accepted before code (W5.0 spike note appended before W5.1)
- [ ] Core service + PHP Artisan + HTTP + MCP over one core (R44), or the
      documented exception
- [ ] Both flag states tested (R43). The flags: **W1** `KB_RETRIEVAL_EXPLAIN_ENABLED`,
      `KB_FTS_QUERY_SYNTAX`; **W2** `KB_EMBEDDING_CONTEXTUAL_INPUT`; **W3**
      `KB_CROSS_RERANK_ENABLED`; **W4** `KB_QUERY_EXPANSION_ENABLED`,
      `KB_MCP_FACETS_ENABLED`, `KB_PATH_CONTEXTS_ENABLED`; **W5**
      `KB_CODE_INGESTION_ENABLED`, `KB_CODE_CHUNKER_DRIVER` — all default to
      today's behaviour
- [ ] Every new env var in `.env.example` + `config/kb.php` + doc-site
      `configuration.mdx` in the same PR (R6/R9)
- [ ] R32 matrix rows for every new protected route
- [ ] Tenant scope through relationships (R30/R33); new tenant-aware tables in
      `TenantIdMandatoryTest` (R31)
- [ ] Benchmark v2 LIVE numbers in the closure doc; defaults flipped only on
      evidence
- [ ] Playwright real-data E2E for the path-contexts screen (R12/R13); a11y (R15)
- [ ] Doc-site pages per workstream (R45) and the ADR index row
- [ ] README feature rows + `## Changelog` entry per release (R39); status doc
      `docs/v4-platform/STATUS-<date>-v84x-wN-closure.md` per W
