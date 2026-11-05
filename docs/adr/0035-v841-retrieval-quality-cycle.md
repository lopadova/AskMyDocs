# ADR 0035 — Retrieval quality: explainable, contextual, cross-encoded, expanded, code-aware

- **Status:** Accepted (2026-11-04 — approved by the product owner; cross-encoder drivers: both `http` API and self-hosted selectable via settings/env, default self-hosted; Node available on ingestion workers)
- **Date:** 2026-11-04
- **Amended:** 2026-11-05 — integration with the recursive investigation (main consolidation), W6 (qmd parity closure) and W7 (expansion learning loop); see the addenda at the end.
- **Cycle:** v8.41 → v8.45 ("Retrieval Quality", plan:
  [`PLAN-v8.41-retrieval-quality.md`](../v4-platform/PLAN-v8.41-retrieval-quality.md))
- **Builds on:** the v8.2 retrieval-quality benchmark (`kb:benchmark`,
  `RetrievalQualityMetrics`), the v8.7/W1 synonym expansion (`SynonymExpander`),
  the v3 pluggable pipeline (`PipelineRegistry`, R23), [ADR 0015](0015-v816-provider-sdk-migration.md)
  (all providers on `laravel/ai`), [ADR 0029](0029-v836-ocr-converter-drivers-and-ocr-provenance.md)
  (external-binary drivers behind one contract, `SourceType` knows a type while
  a flag gates acceptance, FinOps metering of a non-SDK call), R3 bounded bulk
  work, R14 loud failures, R26 provable short-circuits, R27 additive response
  shapes, R30/R31 tenant scoping, R33 authorization in SQL, R43 both-state flags,
  R44 tri-surface, SEC-LLM-001 (provider allow-list, PII before egress, model
  output is data).
- **External reference studied:** `tobi/qmd` at `86d2365` (2026-10-14) — an
  on-device markdown search engine (SQLite FTS5 + `sqlite-vec` + local GGUF
  models). Its *platform* is not comparable to ours (single user, no tenancy,
  no ACL, no answer generation); its *ranking tail* is ahead of ours on three
  measurable points. This ADR adopts those points and rejects the rest
  explicitly (§ "Rejected").

---

## Context

The retrieval hot path today (`KbSearchService::search()`):

1. embed the (synonym-expanded) query through `EmbeddingCacheService`;
2. over-retrieve `limit × kb.reranking.candidate_multiplier` (default 8 × 3 = 24)
   chunks by pgvector cosine, scoped by tenant, `RetrievalFilters` and
   `AccessScopeScope` (R33);
3. **only when `KB_HYBRID_SEARCH_ENABLED=true` (default `false`)**, merge a
   `plainto_tsquery` + `ts_rank` full-text list through a two-list RRF
   (`rrf_k=60`, `semantic_weight=0.7`, `fts_weight=0.3`);
4. post-filter folder globs, min-max normalise the vector signal
   (`KB_RERANK_NORMALIZE_SCORES`, default `true`);
5. `Reranker::rerank()` — a **lexical heuristic**: `0.55·vector + 0.25·keyword +
   0.05·heading`, plus tag overlap, recency, preamble match, canonical priority
   boost, canonical status penalty, @mention boost. **No model reads the
   query and the chunk together.**

`searchWithContext()` then adds `GraphExpander` (1 hop of `kb_edges`) and
`RejectedApproachInjector`, and the prompt is composed from
`resources/views/prompts/kb_rag.blade.php`.

Five observations, each verified in the code:

- **O1 — the reranker never reads the pair (query, chunk).** Every signal is a
  function of overlap or metadata. Paraphrases ("come si annulla un ordine?" vs
  a chunk titled "Storno documenti") are carried by the bi-encoder alone. A
  cross-encoder is the standard remedy and the largest single precision gain
  available to us.
- **O2 — chunks are embedded without their document.** `DocumentIngestor`
  passes `ChunkDraft::$text` to `EmbeddingCacheService::generate()`
  (`DocumentIngestor.php`, the `array_map(fn (ChunkDraft $d) => $d->text, …)`
  call). A chunk "Il timeout è 30 s" carries no trace of *which* system or
  section it describes, although `heading_path` and the document title are
  known at that moment.
- **O3 — nothing records how a vector was produced.** The cache key is
  `(text_hash, provider, model)`; the chunking parameters and the input
  template are not part of any key or column, so a change to either silently
  mixes incompatible vectors in one index.
- **O4 — retrieval is not explainable per result.** `SearchResult::$meta`
  reports aggregates (fusion method, counts, flags). When the benchmark moves,
  nobody can say *which* signal moved a chunk.
- **O5 — our benchmark is saturated.** `resources/benchmark/` holds 5 documents
  and 14 queries; the v8.2 run already scores nDCG@5 0.997 / MRR 1.000. It
  cannot measure any of the improvements below — every one of them would
  "pass" and none would be proven.

Two further gaps, smaller but cheap:

- **O6 — the lexical query has no syntax.** `plainto_tsquery` ignores quotes
  and negation; compound identifiers (`DEC-0054`, `src/lib/i18n.ts`) are not
  matched as phrases.
- **O7 — agents get search but not orientation.** The MCP surface
  (`KbSearchTool`, `KbReadDocumentTool`, `KbReadChunkTool`, …) offers no way to
  discover which filter values exist, no line-range read, no "did you mean"
  on a miss, and no per-folder description of what a part of the corpus is.

And one capability gap the user asked to close:

- **O8 — source code cannot be ingested at all.** `SourceType::fromExtension()`
  maps only `md`, `markdown`, `txt`, `pdf`, `docx` and image extensions; a
  `.php` or `.ts` file resolves to `UNKNOWN` and is rejected at the entry
  points. No connector carries code (the shipped connectors are Confluence,
  Discord, Evernote, Fabric, Google Drive, IMAP, Jira, MCP, Notion, OneDrive,
  WhatsApp and the API connector), and the GitHub Action collects `*.md` /
  `*.markdown` only. An AST chunker alone would therefore chunk nothing: the
  capability is a **new content class**, not a chunker swap.

---

## Decision

Seven decisions, D1–D7, delivered in the order of the plan (measurement first).
Every new behaviour is behind a flag that defaults to the current behaviour
(R43) and is promoted to default-ON only by a benchmark run that proves it.

### D1 — Measure first: benchmark v2 and a per-result `explain` trace

**Benchmark v2.** A second labelled set under `resources/benchmark/v2/`
(corpus + `queries.yaml`, same schema as v1, v1 kept untouched as a regression
floor). Minimum composition, chosen so each later decision has queries that can
*fail*:

| Class | Min. queries | Proves |
|---|---|---|
| Paraphrase / no lexical overlap | 15 | D4 (cross-encoder), D2 (contextual embedding) |
| Hard negatives (same vocabulary, wrong answer) | 15 | D4 |
| Identifier / exact phrase / negation | 10 | D3 (lexical syntax) |
| Context-dependent chunk ("the timeout is 30 s") | 10 | D2 |
| Short / ambiguous queries that need expansion | 10 | D5 |
| Italian and English, mixed | throughout | language routing does not regress |
| Code questions (symbol, behaviour, location) | 10 | D7 |
| Refusals (answer not in corpus) | 10 | no decision may raise false grounding |

Corpus ≥ 40 documents across markdown, PDF, DOCX, at least one canonical
cluster with `kb_edges`, one rejected-approach doc, and (for D7) a small code
tree. All synthetic — no customer data. The `--gate` thresholds stay the v8.2
enterprise floor on v1 and are **set on v2 from the first baseline run**
(baseline − 0.02 tolerance), so a regression fails CI from W1 onward.

**`explain` trace.** An opt-in, per-result decomposition. `KbSearchService`
gains an `explain` toggle on `RetrievalFilters` (additive field, default
`false`); when on, every returned chunk carries an `explain` array:

```text
explain = {
  lists: [ {source: vector|fts|expansion:<type>, query, rank, weight,
            backend_score, rrf_contribution} … ],
  rrf: {base, total},
  heuristic: {vector, keyword, heading, tags, recency, preamble,
              canonical_boost, status_penalty, mention_boost, total},
  cross_encoder: {model, score, retrieval_weight, blended} | null,
  graph: {via_edge, from_doc} | null
}
```

It is computed from the values the pipeline already holds — no extra query, no
extra provider call — and is `null`-cost when off (the branch is skipped, R26
style test proves no extra work). It never contains chunk text beyond what the
result already returns, and never contains provider bodies (SEC-LLM-001 gate 8).

Tri-surface (R44), one core (`KbSearchService` + a `RetrievalExplainPresenter`):

- **PHP:** `php artisan kb:search {query} {--project=} {--tenant=default}
  {--limit=8} {--explain}` (new; read-only).
- **HTTP:** `POST /api/admin/kb/retrieval/explain` in the
  `role:admin|super-admin` group — R32 matrix row in the same PR.
- **MCP:** `KbRetrievalExplainTool` (read-only), registered on
  `KnowledgeBaseServer`; returns the trace for the MCP-resolved tenant.

### D2 — Contextual embeddings, with an embedding fingerprint

**Input template.** The text sent to the embeddings provider for a chunk
becomes a fixed, versioned template instead of the bare chunk:

```text
title: {document title} | section: {heading_path or "none"} | text: {chunk}
```

Canonical documents add `| type: {canonical_type}`; code chunks (D7) use
`file: {path} | symbol: {symbol_path} | lang: {language} | code: {chunk}`.
`knowledge_chunks.chunk_text` stays the **bare** chunk — the template only
shapes the vector, never what is shown, cited or full-text indexed.

Query embeddings stay as today in W2 (asymmetric query prefixes depend on the
model family and are measured separately; the template's `version` makes adding
one later a fingerprint change, not a silent drift).

**Fingerprint.** `EmbeddingInputTemplate` exposes `fingerprint()` =
first 16 hex chars of `sha256(provider | model | dimensions | template_version |
target_tokens | overlap_tokens | hard_cap_tokens)`. A new nullable column
`knowledge_chunks.embedding_fingerprint` (string 16, indexed together with
`tenant_id`) records it on every chunk write.

Because the cache key is `sha256` of the **text actually embedded**, putting
the template output through `EmbeddingCacheService::generate()` changes the
cache key by construction — **no change to the `embedding_cache` UNIQUE is
needed**, and old and new vectors can never be confused in the cache.

**PII.** The composed input contains the title and heading, which may carry
personal data the chunk body does not. The composed string, not just the chunk
body, therefore goes through the same `ChunkRedactor` seam before the first
embedding (ADR 0029 §9 posture). A test asserts a PII-bearing title never
reaches the provider fake.

**Re-embedding.** `kb:reembed-stale {--project=} {--tenant=default}
{--batch=100} {--dry-run}` selects chunks whose `embedding_fingerprint` is
`null` or differs from the active fingerprint, `chunkById(100)` (R3), and
queues re-embeds; the dry run reports count and an estimated embedding cost
(FinOps price table). The read surface (count of stale chunks per project) is
added to the existing `KbIngestionStatusTool` / ingestion-status endpoint
(additive key `stale_embeddings`, R27) rather than inventing a fourth surface.

**Flag:** `KB_EMBEDDING_CONTEXTUAL_INPUT` (default `false`). OFF = byte-identical
inputs to today (asserted). ON = new template for new writes; existing chunks are
migrated only by the explicit `kb:reembed-stale` run — a flag flip never
re-embeds a corpus implicitly.

### D3 — Lexical query syntax and the hybrid default

- `plainto_tsquery` → `websearch_to_tsquery` (native Postgres: `"exact phrase"`,
  `-exclusion`, `or`). Each synonym phrase from `SynonymExpander` keeps its own
  `websearch_to_tsquery` call OR-ed in, exactly as today's per-phrase
  `plainto_tsquery` expansion.
- A single bare term gets a prefix match (`term:*`) — built from
  `to_tsquery` with the term **sanitised to `[\p{L}\p{N}]+`** before
  interpolation into a bound parameter (never concatenated, SEC-RAWSQL-001).
- A term containing separators (`DEC-0054`, `i18n.ts`, `2026.4.10`) is matched
  as the phrase of its parts (`phraseto_tsquery`), mirroring how the index
  tokenises it.
- `ts_rank` → `ts_rank_cd(…, 32)` so the lexical score is bounded `0..1`
  (required by D5's strong-signal test; the RRF itself uses ranks, not scores,
  so this does not change fusion).
- **Hybrid default.** `KB_HYBRID_SEARCH_ENABLED` is promoted to `true` **only**
  if benchmark v2 shows nDCG@5 and MRR not lower than the vector-only baseline
  and refusal accuracy unchanged; the decision and numbers are recorded in the
  W1 closure doc. If not, the flag stays `false` and the reason is documented.

Flag: `KB_FTS_QUERY_SYNTAX` (`websearch` | `plain`, default `plain` until the
W1 benchmark decides).

### D4 — A cross-encoder stage with position-aware blending

**Where.** After `Reranker::rerank()`, before the final slice. `Reranker` is
called with `limit = kb.cross_rerank.candidates` (default 30) instead of the
final `limit`; the new `CrossEncoderStage` re-orders those candidates and
`search()` slices to `limit` afterwards. With the stage off, `Reranker` is
called exactly as today (asserted).

**Contract.** `App\Services\Kb\Retrieval\CrossEncoder\CrossEncoderReranker`:
`score(string $query, list<string> $passages): list<float>` (0..1, same order).
Drivers, selected by `KB_CROSS_RERANK_DRIVER`:

- `http` — a `/rerank`-style JSON API (hosted rerank APIs, or a self-hosted
  inference server serving a cross-encoder such as Qwen3-Reranker). Host is an
  **exact allow-list** in `config/kb.php` (SEC-SSRF-001, ARCH-HTTP-001), bounded
  timeout and body size, response validated against a closed shape (count must
  equal the input count, every score finite).
- `fake` — deterministic, tests and benchmark `--stub` only; refused in
  production by the posture check.

There is deliberately **no** "chat model as reranker" driver: a generative model
asked to grade 30 passages is slower, costlier and less calibrated than a
cross-encoder, and turns ranking into an injection surface.

**Blend.** Let `h` be the heuristic score min-max normalised over the candidate
set **excluding** the mention boost, `c` the cross-encoder score, `r` the
heuristic rank:

```text
w(r) = 0.75 if r ≤ 3, 0.60 if r ≤ 10, 0.40 otherwise   (configurable tiers)
c'   = c × (1 − status_penalty_normalised)
final = w(r)·h + (1 − w(r))·c' + mention_boost
```

- The tiers protect the head: an exact canonical match the heuristic put first
  cannot be buried by a cross-encoder that disagrees (the failure mode qmd's
  position-aware blend exists to prevent). Unlike qmd we blend a **calibrated
  score**, not `1/rank`, so a large heuristic margin is not flattened.
- The status penalty is applied to `c` too, so a deprecated or superseded doc
  cannot regain its rank through text similarity.
- The @mention boost is added after the blend, so an explicit user mention is
  never overridden.
- **Refusal is unchanged in this cycle:** the refusal floor keeps reading the
  raw `vector_score`. Using `c` as a refusal signal is a later decision with its
  own benchmark (Deferred).

**Egress and cost.** The query passes through the repository PII policy before
egress (SEC-LLM-001 gate 3); passages are already redacted at ingest when the
redactor is active. Driver/host/model come from the code-owned allow-list; tenant
settings may only choose among them. Each call is metered by a
`CrossRerankCallMeter` under purpose tag `cross_rerank`, following the
`OcrCallMeter` precedent for a non-SDK call.

**Cache.** Laravel cache store, key `sha256(tenant | model | sha256(query) |
chunk_hash)`, value = score only, TTL `KB_CROSS_RERANK_CACHE_TTL` (default 7
days). Identical passages are scored once per request. No query or chunk text is
stored.

**Failure.** Timeout, non-2xx or invalid shape ⇒ the heuristic order is used,
`meta.cross_encoder = {status: "unavailable", reason: <code>}` (R14 — the
caller can tell), a structured warning is logged with the correlation id, and no
exception reaches the chat path.

**Flag:** `KB_CROSS_RERANK_ENABLED` (default `false`). Off-path proven with
`shouldNotReceive('score')` (R26).

### D5 — Typed query expansion with a strong-signal skip

**Generation.** One no-tools chat call through `AiManager` (SDK path, therefore
metered by the FinOps lifecycle hook) to a cheap model chosen from the provider
allow-list (`KB_QUERY_EXPANSION_PROVIDER`, `KB_QUERY_EXPANSION_MODEL`). Fixed
system prompt; the user query is untrusted data in the user role. Output is a
**closed JSON schema**:

```json
{ "lex": ["≤3 keyword variants"], "vec": ["≤3 paraphrases"], "hyde": "≤1 passage, ≤400 chars" }
```

Unknown keys, over-length strings or non-string values ⇒ the whole expansion is
discarded (not repaired). Each variant must share at least one significant term
with the original query, otherwise it is dropped (prevents semantic drift — the
qmd guard). Variants equal to the original are dropped.

**Routing.** `lex` → the full-text branch (D3 syntax); `vec` and `hyde` → one
batched `EmbeddingCacheService::generate()` call, then the vector branch. The
original query still goes to both branches.

**Fusion.** `reciprocalRankFusion()` generalises from two lists to *N* weighted
lists: original-query lists weight `2.0`, expansion lists `1.0`, independent of
insertion order; top-rank bonus `+0.05` (rank 1) / `+0.02` (ranks 2–3) on any
list. Graph expansion and rejected-approach injection keep operating on the
fused primary set, unchanged.

**Strong-signal skip.** Before generating, if the top lexical hit's bounded
score ≥ `KB_QUERY_EXPANSION_SKIP_MIN_SCORE` and its gap to the second ≥
`KB_QUERY_EXPANSION_SKIP_MIN_GAP` (both calibrated on benchmark v2; when hybrid
is off the same test runs on the top-1 cosine), no expansion is generated. The
skip is disabled when the caller passes an `intent`. Proven with
`shouldNotReceive('chat')` (R26) and counted in `explain`.

**`intent`.** An optional disambiguation string on the search surfaces. It is
passed to the cross-encoder (prefixed to the query) and to snippet/best-chunk
selection — **never** to the expansion model (qmd observed the model copying
intent meta-language into sub-queries).

**Cache.** Laravel cache, key `sha256(tenant | project | model | query)`, TTL
`KB_QUERY_EXPANSION_CACHE_TTL` (default 24 h). An expansion whose sub-queries
all returned nothing is evicted, so a dud is not replayed on every warm repeat.

**Layering with synonyms.** `SynonymExpander` (tenant/project dictionary) runs
first and stays deterministic; the LLM expansion is additive on top.

**Flag:** `KB_QUERY_EXPANSION_ENABLED` (default `false`). The latency added (one
chat round-trip) is reported as p50/p95 in the W4 closure doc, alongside the
skip rate on benchmark v2.

### D6 — Agent affordances on the MCP surface

Each item is tri-surface over one core service, tenant-scoped (R30) and
ACL-scoped (R33) — an agent can never discover the *existence* of a value it
could not retrieve.

1. **Facets discovery** — `KbFacetsService::for(tenant, project?, filters?)`
   returns the filterable dimensions with counts: `source_types`,
   `canonical_types`, `canonical_statuses`, `languages`, `tags`, top-level
   folder prefixes (depth ≤ 2), `source_updated_at` range. Counts come from SQL
   (`GROUP BY`, bounded by `facet_limit`, R3/R18) under the same scopes as
   retrieval. Surfaces: `kb:facets`, `GET /api/kb/facets` (authenticated, the
   chat filter UI can adopt it later), `KbFacetsTool`.
2. **Line-range read** — `KbReadDocumentTool` gains optional `from_line` and
   `max_lines` (additive schema, R27); the HTTP preview endpoint gains the same
   query parameters; artisan `kb:show {document} {--from=} {--lines=}`. Search
   results add `start_line` / `end_line` per chunk when known (markdown chunker
   and code chunker record them in chunk metadata), so an agent can go from a
   citation to the exact lines.
3. **"Did you mean"** — on a miss, `KbDocumentBySlugTool` and the read tools
   return up to 5 similar slugs / source paths (Levenshtein ≤ 3, computed only
   over documents visible to the caller, candidate set bounded by a prefix
   query). Same payload on the HTTP 404 body (additive).
4. **Path contexts** — admin-authored descriptions bound to a `source_path`
   prefix (`hr/policies/` → "Policy HR vigenti, approvate dal DPO"). New table
   `kb_path_contexts` (`tenant_id`, `project_key`, `path_prefix`,
   `description` ≤ 500, timestamps; UNIQUE `(tenant_id, project_key,
   path_prefix)`; R31). The chain of matching prefixes (general → specific) is
   returned with each citation and rendered in the prompt as a delimited,
   untrusted **data** block above `## Context` — never as system instructions
   (SEC-LLM-001 gate 4). Every mutation writes `admin_command_audit`.
   Surfaces: `kb:path-context {add|list|remove}`,
   `GET|PUT|DELETE /api/admin/kb/path-contexts` (`role:admin|super-admin`,
   R32 row), `KbPathContextsTool` (read-only — writing prompt-visible text is
   kept off the agent surface, documented R44 exception).

Flags: `KB_MCP_FACETS_ENABLED`, `KB_PATH_CONTEXTS_ENABLED` (both default
`false`); the line-range and did-you-mean additions are additive and unflagged
(they change no existing response field).

### D7 — Source code as a content class, chunked on the syntax tree

**Why it was not in the first proposal.** It was listed as "watch, not now"
for three reasons, none of them a blocker: (1) there is no code ingestion path
today (O8), so the work is a new content class, not a chunker; (2) tree-sitter
has no maintained PHP binding, so the parser must live outside the PHP process;
(3) code brings governance questions prose does not — secrets in source,
generated/vendored files, volume. This decision answers all three.

**Content class.** `SourceType::CODE` (`code`) is always known; acceptance is
gated at the entry points by `KB_CODE_INGESTION_ENABLED` (default `false`) —
the exact pattern ADR 0029 §2 uses for images. A static extension → language
map (`php`, `ts`, `tsx`, `js`, `jsx`, `mjs`, `py`, `go`, `rs`, `java`, `cs`,
`rb`, `sql`, `yml`, `yaml`, `json`, `sh` — the AST-capable subset is decided by
grammar availability, the rest uses the line heuristic) and `text/x-<lang>`
MIME tokens in `mime_to_source_type`. A `CodePassthroughConverter` wraps the
source in a fenced block with the language tag and records `language` in
`extractionMeta`.

**Entry points.** `kb:ingest-folder --pattern=php,ts`, `POST /api/kb/ingest`
(`mime_type` or extension), and the GitHub Action (new input `code-globs`,
default empty = off; diff mode uses the same `AMR`/`D`+`R` sets as markdown,
R5). A dedicated GitHub/GitLab connector is out of scope (Deferred).

**Exclusions and secrets — before anything is stored or embedded.**

- Path deny-list, non-overridable: `vendor/`, `node_modules/`, `.git/`,
  `dist/`, `build/`, `storage/`, `*.min.*`, lockfiles, `.env*`, `*.pem`,
  `*.key`, `*.p12`, `id_rsa*`. Operator globs can add, never remove.
- Size cap `KB_CODE_MAX_FILE_BYTES` (default 512 KB) and a generated-file
  heuristic (header markers such as `@generated` / `DO NOT EDIT`).
- A `SecretRedactor` pass (known credential formats + high-entropy assignments
  to secret-like names) runs at the `ChunkRedactor` seam and replaces matches
  with `[REDACTED_SECRET]` before the first embedding and before persistence.
  A finding is counted in ingestion status, never logged with its value
  (SEC-LOG-001).

**Parser placement.** A Node helper using `web-tree-sitter` (WASM) with pinned
prebuilt `.wasm` grammars, invoked from PHP through `Symfony\Component\Process`
with an **argument array**, the source on stdin, a timeout
(`KB_CODE_PARSE_TIMEOUT`, default 10 s) and an output cap. It returns only JSON
break points `{pos, line, score, type, symbol_path}` — never code. Rationale:

| Option | Verdict |
|---|---|
| **`web-tree-sitter` (WASM) in a Node helper via `Process`** | **Chosen.** Memory-safe sandbox; a malformed file cannot crash the PHP worker; prebuilt grammars, no C toolchain at deploy; timeout and kill are trivial; same library a reference implementation (qmd) runs in production. |
| PHP FFI to `libtree-sitter` | Rejected: a native fault kills the queue worker; grammars must be compiled per platform; `ffi.enable` policy varies by SAPI. |
| `tree-sitter` CLI | Rejected: grammars compiled at deploy (C compiler on the image); output format not a stable contract across CLI versions. |
| `nikic/php-parser` and per-language PHP parsers | Rejected as the general solution (one language each); acceptable later as a pure-PHP fast path for PHP files only. |

**Chunker.** `CodeAstChunker` (`supports('code')`, mutually exclusive with every
registered chunker — `PipelineRegistryChunkerMutexTest` extended, R23) uses the
scored break-point algorithm: syntax boundaries (import block, class/interface,
function/method, top-level statement) get high scores, blank lines low; the cut
is chosen inside a look-back window with a squared-distance decay; chunks never
split a node smaller than the hard cap. `heading_path` becomes the symbol path
(`src/Billing/Invoice.php › class Invoice › total()`); metadata records
`language`, `symbol`, `start_line`, `end_line`. Budgets reuse
`kb.chunking.target_tokens` / `hard_cap_tokens` / `overlap_tokens`.

**Degradation (R43).** `KB_CODE_CHUNKER_DRIVER` = `auto` (default) |
`tree_sitter` | `heuristic`. `auto` uses tree-sitter when the helper answers a
health probe and the grammar exists, otherwise the **heuristic** driver
(language-aware line patterns for top-level declarations + blank lines) —
still structurally better than the markdown chunker for code. The driver
actually used is recorded per chunk (`metadata.chunker`) and surfaced in
ingestion status. Forcing `tree_sitter` when unavailable fails the ingest
loudly (R14), never silently degrades.

**Full-text for code.** Language stemmers damage identifiers. Code chunks are
full-text searched with the `simple` configuration through a partial expression
index `to_tsvector('simple', chunk_text) WHERE metadata->>'language' IS NOT NULL`
(pgsql only, no-op elsewhere, like the existing GIN index).

**Observability (R44).** Chunking is an internal pipeline stage, not a
caller-facing capability; its state is exposed on the existing surfaces instead
of a new one: grammar/helper availability on the admin health check
(`HealthCheckService`), per-document chunker and secret-redaction counts on the
ingestion status (artisan + HTTP + `KbIngestionStatusTool`). Documented R44
exception.

---

## Consequences

**Positive**

- Precision gains are **proven, not assumed**: every promotion to default-ON is
  tied to a benchmark v2 number recorded in a closure doc, and v2 becomes a CI
  gate from W1.
- Debuggability: `explain` turns "the answer got worse" into "the cross-encoder
  demoted chunk 4182 from rank 2 to 9", for operators, support and agents.
- The index becomes self-describing (fingerprint); provider/model/chunking
  changes become an explicit, costed, resumable migration
  (`kb:reembed-stale --dry-run`).
- Agents waste fewer turns: they can see the filter domain, read exact lines and
  recover from a wrong slug.
- Code repositories become first-class knowledge, chunked on their own
  structure, with secrets stripped before storage.

**Negative / costs**

- Two new outbound dependencies on the hot path when enabled (cross-encoder,
  expansion model): latency and a failure mode each — both degrade to today's
  behaviour, both visible in `meta`.
- Contextual embeddings require a full re-embed to take effect (cost estimated
  by the dry run before anything is spent).
- A Node runtime on the ingestion workers becomes a soft dependency for AST
  chunking (the heuristic driver keeps code ingestion working without it).
- Benchmark v2 is real authoring work (≈ 40 docs, ≈ 90 labelled queries) and
  must be maintained as features land.

**Neutral**

- No change to tenancy, ACL, canonical layer, promotion, deletion or audit
  paths. `chunk_text`, citations and the refusal floor keep their semantics.

## Rejected (from the qmd study)

- **Local GGUF inference inside the app process** — our deployment model is
  server-side and multi-tenant; models are reached through the provider layer.
  (Still rejected after W6: the `self_hosted` expansion profile reaches an
  operator-run model **over HTTP**, behind an exact host allow-list — the model
  runs next to the app, never inside the PHP process. See the W6 addendum.)
- **Unauthenticated HTTP endpoints / origin-only protection** — our surfaces are
  Sanctum + RBAC + tenant scoped.
- **Document-level fusion** (qmd fuses per file) — our citations are per chunk;
  fusion stays per chunk.
- ~~**Fine-tuning our own expansion model** — deferred until D5 has produced real
  query logs and a scoring function; not justified before that.~~ **Superseded by
  the W7 addendum.** The *infrastructure* now ships (signal recording, scoring
  function, dataset builder, training backends, human promotion, rollback); what
  remains outside this repository is the *execution* of a GPU training run and
  the real query logs it needs. It is therefore no longer "rejected", and no
  longer simply "deferred" either — see W7 below.

## Deferred

- Cross-encoder score as a refusal signal (needs its own calibration set).
- Asymmetric query-embedding prefixes per model family.
- A GitHub/GitLab connector package (`padosoft/askmydocs-connector-*`) with
  webhook-driven incremental sync of repositories.
- Symbol-level graph edges from code (imports/calls → `kb_edges`).
- Pure-PHP fast path for PHP files (`nikic/php-parser`).
- (W7) Running the fine-tuning itself: the `export` backend hands a JSONL to an
  external trainer; the `openai` backend delegates to the provider. No GPU
  training runs in this platform, by design.
- (W7) A tenant-corpus gate: today's gate measures the labelled benchmark-v2
  corpus, not the tenant's own documents (no tenant has labelled queries yet).
- (W7) An explicit consent record per tenant: the training consent is an
  operator-maintained env list, not a DB-backed, audited consent grant.

## Test plan (summary — per-workstream detail in the plan)

- Every flag: OFF path byte-identical to today, ON path exercised (R43).
- Every short-circuit: `shouldNotReceive` proof (R26).
- Cross-tenant: facets, path contexts, did-you-mean and explain never reveal
  another tenant's or an ACL-hidden document (R30/R33), tested through the
  relationship, not only the controller.
- Contract tests for the cross-encoder and expansion parsers: malformed,
  oversized, wrong-count, non-finite, unknown-key responses all rejected.
- PII: a PII-bearing title/heading never reaches the embeddings fake when the
  redactor is active; secrets in code never reach storage or embeddings.
- Benchmark v2 `--gate` in CI from W1; v1 `--gate` kept.

## Acceptance criteria (cycle)

1. Benchmark v2 exists, runs in CI with `--gate`, and its baseline is recorded.
2. With D2 + D4 enabled, benchmark v2 nDCG@5 and MRR improve over the W1
   baseline, refusal accuracy does not drop, and the numbers are in a closure
   doc. If a decision does not improve the benchmark, it ships default-OFF with
   the evidence — it is never enabled on faith.
3. All new capabilities are tri-surface or carry a documented R44 exception.
4. A repository of PHP/TypeScript code can be ingested through the CLI, the API
   and the GitHub Action, chunked per symbol, cited with line ranges, with
   secrets redacted, and the code queries in benchmark v2 pass.

---

## Addendum — W5 spike note and as-built record (v8.45, 2026-11-05)

### Spike result (plan W5.0)

- **Pinned:** `web-tree-sitter` `0.25.10` + the prebuilt grammar pack
  `tree-sitter-wasms` `0.1.13` (exact versions in `tools/code-chunker/package.json`;
  no lockfile is committed, the exact pins are the reproducibility contract).
  The helper needs no C toolchain; Node ≥ 18.
- **Grammars shipped (11):** PHP, TypeScript, TSX, JavaScript (`js`/`jsx`/`mjs`/`cjs`),
  Python, Go, Rust, Java, C#, Ruby, Bash. The pack ships **no SQL grammar** and YAML/JSON
  carry no useful declaration structure, so those three languages use the heuristic
  driver by design (moved to the heuristic, not dropped — as the plan required).
- **Measured on the build machine** (Node 22, one process per file, which is the real
  invocation model): ≈ 55 MB resident for the helper, ≈ 10 ms to parse a small PHP
  file, **≈ 120 ms wall per file including WASM start-up** — 300 PHP files of this
  repository in 36 s sequentially. A 2 000-file repository is therefore ≈ 4 minutes of
  CPU, but spread across independent queue jobs it is invisible to the operator.
  The 2 000-file figure is an extrapolation of the 300-file measurement, not a
  separate run. If start-up ever dominates, a long-lived worker mode is a drop-in
  behind the same driver contract.
- Same break points from both drivers on the fixtures (PHP class + methods,
  TypeScript exports/arrows, Python decorators, Go methods) — the heuristic is a
  faithful floor, not a different algorithm.

### As-built deviations from the text above

| ADR text | As built | Why |
|---|---|---|
| Helper returns `{pos, line, …}` | Returns **`line` / `end_line`** only | JS offsets are UTF-16 code units, PHP's are bytes; lines are the only unit both agree on across multi-byte text |
| `heading_path` uses `›` and kind words (`class Invoice › total()`) | `file > Invoice > total` | Matches `MarkdownChunker`'s `' > '` separator that the reranker's heading signal and the UI already parse; the break-point type is in `metadata`, not the path |
| Secret pass "at the `ChunkRedactor` seam" | Redaction **in `CodePassthroughConverter`** (before the artifact exists), again at the seam, and before the raw file is written by `POST /api/kb/ingest` | A seam-only pass would still let the secret into the stored conversion artifact and the KB-disk copy |
| Deny-listed files fail the run | They are **skipped with a reason** (`skipped_code`, API `status: "skipped"`) | A repository push legitimately contains `vendor/` and lockfiles; one must not 422 the other ninety-nine |
| Code accepted wherever `SourceType` is known | `fromExtension()` maps code only on opt-in (`$includeCode`); the **default `--pattern` never includes code** | A docs folder with a few `.json`/`.yml` files must not start ingesting configuration when the flag is flipped |
| Code embedding header "already supported" under D2's flag | Code chunks **always** use `file \| symbol \| lang \| code`, even with `KB_EMBEDDING_CONTEXTUAL_INPUT=false` | A bare function slice has nothing for the bi-encoder to match a "where is X defined" query against; prose behaviour is unchanged |
| Unknown `KB_CODE_CHUNKER_DRIVER` | Resolves to `heuristic` | Unknown policy must not widen the process-execution surface |
| Queued job after the flag is turned off | Completes; deny-list/size/binary/generated still apply to its bytes | The master switch is an entry-point gate (as for images); the controls are not |
| Full text for code | Second code-only pass with `simple`, merged by chunk id, via the partial index `idx_chunks_fts_code` | The ADR specified the index; the query side had to be added for it to be used |

### What was verified and what was not

Verified here: the helper on PHP/TS/Python/Go; both drivers on real repository files;
the degradation ladder with fake helpers (timeout, crash, garbage, oversize output);
the partial-index plan on PostgreSQL 16 (`Bitmap Index Scan on idx_chunks_fts_code`);
the GitHub Action collect and POST steps against a temporary git repository.
**Not verified here:** retrieval quality on real embeddings — the benchmark's `code`
class runs against the deterministic stub, so it proves ingestion and scoring work end
to end, not that code questions are answered better. That measurement belongs to a
live run with a real provider and pgvector (see the closure document).

---

## Addendum — Integration with the recursive investigation (main consolidation, 2026-11-05)

The develop → main consolidation (`docs/releases/2026-11-05-develop-main/`) was
merged into the cycle branch (`42506cca`). It brought a new chat hot path —
`App\Services\Kb\Investigation\KbInvestigationService`, a retrieve → read →
assess loop in which **the raw user message is never sent to vector search**: an
interpreter model rewrites it into round queries (`kbQueries`), each round is
executed through the ordinary tenant/project/ACL-aware retrieval service, and the
loop stops on a typed `stop_reason`. Chat now runs that loop whenever
`KB_RECURSIVE_RETRIEVAL_ENABLED` is `true` (the default). Everything this ADR
built still sits *underneath* it; five integration decisions were needed.

### I1 — Model-written queries are never expanded again

**Decision.** `RetrievalFilters` gains `skipExpansion` (not a filter — a
caller declaration), set through `RetrievalFilters::withoutExpansion()` by every
caller whose query was already written by a model:
`KbInvestigationService::modelWrittenQueryFilters()`, the agent planner's search
(`App\Agent\AgentLoop`) and the wave search
(`App\Agent\Waves\AgentWaveKnowledgeSearch`). `KbSearchService::expansionLists()`
then reports `meta.query_expansion = {status: "skipped", reason:
"model_written_query"}` and makes no call.

**Why.** D5 exists to repair a *human* query that shares few words with the
document. A round query is already a model's rewrite: expanding it pays a second
LLM call for the same job and drifts further from the user's words (a rewrite of
a rewrite). The skip is checked right after the master switch and **before**
the strong-signal test and any model call; the test counts provider calls and
asserts zero (R26 — `QmdParityStagesTest::test_a_model_written_query_is_never_expanded_again`);
`withoutExpansion()` preserves every other filter
(`test_without_expansion_keeps_every_other_filter`).

**Consequence (stated, not hidden).** With the investigation ON, chat turns no
longer reach typed expansion at all; expansion — and therefore the W7 learning
signal — comes from the *direct* retrieval surfaces (`kb:search`, the MCP search
tools, the explain surfaces, the benchmark's `direct` path, and chat when the
investigation switch is OFF).

### I2 — The stages are observable inside the investigation's diagnostics

main's `$observe` diagnostics closure (the per-phase timing/wait-reason trace the
run diagnostics read) now also wraps `kb.query_expansion`, `kb.rerank` and
`kb.cross_encoder` in `KbSearchService::search()`, next to the existing
`kb.embedding` / `kb.vector_query` / `kb.full_text_query`. A slow cross-encoder or
expansion call is therefore attributed to its own phase, not to "retrieval".

### I3 — Explain can trace the path the user's answer actually takes

Explaining only the direct retrieval would describe a path chat no longer takes.
The explain core (`RetrievalExplainService::explain()`) accepts
`investigation: true` on all three surfaces — `kb:search --investigation`,
`GET /api/admin/kb/retrieval/explain?investigation=1`, `KbRetrievalExplainTool`
`investigation: true` — runs the investigation and returns
`meta.investigation` = `KbInvestigationResult::trace()` (`status`, `queries`,
`stop_reason`, `selected_documents`, `supported_facts`, `missing_facts`,
`language`, `research_flows`, `attempts`). With the switch OFF the key is
`{status: "disabled", flag: "KB_RECURSIVE_RETRIEVAL_ENABLED"}` and the direct
retrieval is explained (R14: visible, never silent; R27: additive key, absent
when not requested).

### I4 — The benchmark can measure either path

`kb:benchmark --path=direct|investigation` (`BenchmarkRunner::PATH_DIRECT` /
`PATH_INVESTIGATION`, an unknown value fails). The scorecard records `path`, and
every row an additive `investigation` = `{status, stop_reason, rounds}` (null on
the direct path). The investigation path needs a chat provider and a retrieval
profile: it is a **live** measurement, never a `--stub` one.

### I5 — Code chunks and code symbols on main's machinery

- `CodeAstChunker` never cuts inside a line, so a single line over the hard cap
  (minified bundle, inlined data blob) would have become one oversized chunk. It
  now splits exactly that case with main's shared `TextBudgetSplitter` (UTF-8
  safe, no text dropped) and records `metadata.split = {part, of}`.
- Code symbols are found by main's literal identifier lookup
  (`KbSearchService::exactIdentifierCandidates()`, backed by the `pg_trgm`
  indexes of `2026_10_22_000002_add_literal_lookup_trigram_indexes`), which the
  investigation uses for target identifiers — pinned by
  `test_exact_identifier_lookup_finds_a_code_symbol`. No second identifier
  index was added.
- The path-contexts admin screen adopted main's interface conventions: IT/EN
  messages (`path-contexts.messages.it.ts`), the shared shadcn-based `SelectField`, and the nav
  entry under **Knowledge › Settings**.

---

## Addendum — W6: qmd parity closure

A second pass over `tobi/qmd` after W5 listed what it still did that we did not.
Six items were adopted; each is default-OFF (R43) unless noted, and each lives on
one core with every caller-facing surface (R44).

| # | qmd behaviour | Our decision | Core |
|---|---|---|---|
| P1 | RRF **top-rank bonus** (protects the head of a list) | Adopted, **original-query lists only** | `Retrieval\TopRankBonus` |
| P2 | `multi_get` — read many documents by path/glob/id in one call | Adopted, tenant + ACL scoped, byte-budgeted | `Agent\DocumentBatchReader` |
| P3 | `#docid` short ids | Adopted as a **content-hash prefix**, ambiguity is an error | `Agent\DocumentReferenceResolver` |
| P4 | Query **documents** (`lex:`/`vec:`/`hyde:`/`intent:` lines) | Adopted, **no model call** | `Retrieval\Expansion\UserQueryDocument` |
| P5 | CLI output: `--min-score`, `--all`, `--line-numbers`, `--files`, `--csv`, `--md` | Adopted on `kb:search`; `min_score`/`line_numbers` on explain HTTP + MCP, `min_score` on `KbSearchTool` | `Retrieval\ResultScoreFloor` |
| P6 | Expansion by a **local** model | Adopted as a `self_hosted` HTTP profile (not in-process) | `Retrieval\Expansion\SelfHostedExpansionClient` + `App\Support\Http\AllowListedEndpoint` |

### P1 — Top-rank bonus

RRF flattens the head: with `k = 60`, rank 1 and rank 3 differ by ~3 %, so an
exact hit for the user's own words can be overtaken by a chunk several
paraphrase lists placed mid-table. `TopRankBonus::for($bestRank)` adds
`kb.hybrid_search.top_rank_bonus.first` (default 0.05) for a best rank of 1 and
`.podium` (0.02) for ranks 2–3 **in the original query's lists only** — an
expansion variant can never earn it, so a model-written rewrite cannot promote a
chunk to the head. Applied in both fusion paths (two-list hybrid RRF and the
N-list expansion fusion). The explain trace reports `rrf = {base,
top_rank_bonus, total}` with `total` equal to the fused score.
Flag `KB_RRF_TOP_RANK_BONUS_ENABLED` (+ `_FIRST`, `_PODIUM`); OFF ⇒ `0.0`
everywhere, fusion byte-identical.

### P2 + P3 — Batch reads and short references

- **References.** `#` + 6–64 lowercase hex resolves to the live document whose
  `document_hash` starts with that prefix; without a leading `#` only an
  unmistakable hash counts (12+ hex with a letter), so a numeric id or a path is
  never read as one. Resolution is tenant-scoped (R30) through Eloquent, so
  soft-delete and `AccessScopeScope` apply (R2, R33); archived versions are
  excluded; an ambiguous prefix raises `AmbiguousDocumentReference` with up to
  five candidates — never a silent first-row pick (R14). Every search result now
  carries `document.ref` (`#` + 12 hex); `kb:show #ref` and
  `KbReadDocumentTool { ref }` open it (on ambiguity `kb:show` lists the
  candidates and fails, the MCP tool returns an error naming them, `multi_get`
  reports `ambiguous_reference` under `missing`).
  *Why a hash prefix and not the row id:* the id changes on every new version;
  the hash names the exact text that was cited.
- **multi_get.** Selectors (≤ 20) are a `#ref`, a glob over `source_path`
  (`KbPath::matchesAnyGlob()` after a `LikeEscaper`-escaped SQL prefix
  pre-filter, R19) or an exact path (`KbPath::normalize()`, R1). Output
  `{documents, omitted, missing, meta}`: at most `kb.multi_get.max_documents`
  (20) documents within a byte budget (`max_bytes`, default 65 536, ceiling
  `max_bytes_ceiling` 262 144); the document that crosses the budget is cut on a
  UTF-8 boundary and flagged `truncated`, the rest are listed under `omitted`,
  unmatched selectors under `missing` with a reason (and suggestions or
  ambiguity candidates). Surfaces: `kb:multi-get`, `POST /api/kb/documents/multi`
  (`token.ability:kb:read`, `throttle:30,1`), `KbMultiGetTool`. Flag
  `KB_MULTI_GET_ENABLED`; OFF ⇒ `404 multi_get_disabled` / `{disabled: true}`.
  *Why an authenticated-reader route and not admin:* every document is read
  under the caller's own ACL, so a batch returns nothing a caller could not open
  one by one.

### P4 — User query documents

With `KB_QUERY_DOCUMENTS_ENABLED=true`, a query whose lines start with `lex:`,
`vec:`, `hyde:` or `intent:` is parsed by `UserQueryDocument::parse()`: plain
lines form the original query, typed lines become a `QueryExpansion` carried on
`RetrievalFilters::$presetExpansion`, and `intent:` fills an absent intent. The
variants feed the same weighted N-list fusion as a model expansion, with
`meta.query_expansion.status = "user_document"` — and **no model is called**
(zero LLM cost, no drift: the author wrote them). It works even when
`KB_QUERY_EXPANSION_ENABLED=false`. Bounds mirror the model contract (≤ 5 `lex`
and `vec`, one `hyde`, length caps, control characters stripped). *Why gated:* a
query that happens to begin with `vec:` today must keep meaning what it means
today until an operator opts in.

### P5 — Search output for tools and pipes

`ResultScoreFloor::apply()` drops results below a caller-chosen floor on the
score they are ordered by (`rerank_score`, else `vector_score`), **after**
ranking — it never reorders and is deliberately not the refusal gate.
`kb:search` adds `--min-score`, `--all` (up to `KB_RETRIEVAL_EXPLAIN_MAX_LIMIT`),
`--line-numbers` (each chunk's line range in the view `kb:show --from` reads),
and mutually exclusive output formats `--files` / `--csv` / `--md` (beside
`--json`). CSV cells that start with `=`, `+`, `-`, `@`, tab or CR are
neutralised (SEC-CSV-001).

### P6 — Self-hosted expansion profile

`KB_QUERY_EXPANSION_PROFILE=self_hosted` sends expansion to an operator-run
OpenAI-compatible `/chat/completions` endpoint (vLLM, llama.cpp server, Ollama,
TGI) instead of the `AiManager` providers — no third-party processor. The
endpoint must pass `AllowListedEndpoint::violation()` against an exact host
allow-list (`KB_QUERY_EXPANSION_SELF_HOSTED_ALLOWED_HOSTS`; https unless
`_ALLOW_HTTP`; no userinfo/query/fragment; no redirects; bounded timeout
`KB_QUERY_EXPANSION_TIMEOUT`, bounded body, closed response shape). The same
helper now backs the cross-encoder's endpoint check (one SSRF rule, not two
copies). No FinOps ledger row: the hardware is the operator's own.

### What W6 did not verify

The bonus values (0.05 / 0.02) are qmd's, not tuned on our benchmark with real
embeddings; P1 therefore stays OFF until a live `kb:benchmark --suite=v2`
comparison exists. The self-hosted profile was tested against a faked endpoint,
not a running vLLM / Ollama.

---

## Addendum — W7: the expansion learning loop

qmd ships a **fine-tuned** query-expansion model; ours called a general model
with a fixed prompt. Fine-tuning was "deferred until D5 produced real query logs
and a scoring function". W7 builds that scoring function and everything after
it, so the loop can start the day real logs exist — and keeps every step that
changes behaviour behind a measured gate or a human.

### The loop

```
SIGNAL → ANALYSE → GATE → APPLY            (weights + few-shot, no training)
                   └→ TRAIN → PROMOTE       (a fine-tuned model, human-promoted)
```

1. **Signal** (`ExpansionSignalRecorder`). After each ranking that an expansion
   influenced — and **only when PII redaction is actually active for the project**
   (`ChunkRedactor::isActive()`; otherwise nothing is recorded, fail-closed) — one
   `kb_expansion_events` row: the **masked** query (one-way `mask`, ≤ 500 chars) and
   its SHA-256, the typed variants (each masked too), the final top-k chunk ids
   (never chunk text), `refused`, and per
   variant type `lex` / `vec` / `hyde`: `lists`, `in_top`, and **`unique_in_top`**
   — top-k chunks that *only* that type returned. `unique_in_top` is the reward:
   the clean measure of "this rewrite found something the user's own words and
   the other rewrites missed". Never blocks the user path (failures logged by
   class only); suppressed while the gate runs, so gate traffic is never data.
2. **Analyse** (`ExpansionLearningService::analyze()`). Over a window
   (`window_days`, 30) with at least `min_events` (30): per type, hit rate =
   events with `unique_in_top > 0` / events where the type produced lists;
   weight = `clamp(0.5 + hit rate, 0.25, 1.5)` (no evidence ⇒ 1.0 neutral). Plus
   up to 4 few-shot examples — the highest-reward, non-refused, not-harmful
   events, keeping only the variant types that earned a unique hit. Admin
   feedback (+1 / −1 per event) adds to the reward or excludes the event.
3. **Gate** (`BenchmarkExpansionTuningGate`, behind the `ExpansionTuningGate`
   contract). Benchmark v2 twice — neutral tuning vs the proposal, through
   `ActiveExpansionTuning::with()` — on nDCG@k and MRR: `passed` (≥ baseline −
   `gate_tolerance`), `failed`, or `not_measurable` (expansion OFF here, a
   benchmark error, or a partial corpus). *Fail-closed:* "no evidence" is never
   "no regression". The corpus is ingested into a dedicated non-operational tenant
   (`kb.expansion_learning.gate_tenant`, default `system-expansion-gate`), never a
   customer's (R30).
4. **Apply.** One `applied` row per (tenant, project) — the service locks the whole
   (tenant, project) scope (R21) and PostgreSQL enforces it with the partial unique
   index `uq_kb_exp_tunings_one_applied`; applying supersedes the previous one;
   `reject` on an applied row is the rollback to neutral. Every mutation (apply,
   reject/rollback, train, promote, retire) writes an `admin_command_audit` row in
   the same transaction (SEC-AUDIT, fail-closed). Auto-apply
   happens **only** with `KB_EXPANSION_LEARNING_AUTO_APPLY=true` **and** a
   `passed` gate. Applied weights multiply each expansion list's weight in the
   N-list fusion; applied examples ride in the **user** turn inside an
   `<examples>` block marked as data (SEC-LLM-001 — they come from user
   queries, so they never enter the system prompt), and the tuning id is part of
   the expansion cache key. `ActiveExpansionTuning` is cached and fails safe: any
   read error ⇒ neutral, exactly the W4 behaviour.
5. **Train** (`train()`). An OpenAI-chat-format SFT dataset from the rewarded
   events (system turn = `QueryExpansionService::systemPrompt()`, so training and
   serving match; assistant turn = only the types that earned a unique hit),
   written to a private disk (R4: a failed write raises
   `TrainingDatasetStorageFailed` → HTTP 503 `storage_failed`, never a "submitted" run),
   then handed to a backend: **`export`** (default — the JSONL stays on the
   operator's disk for an external trainer such as Unsloth/TRL/Axolotl; run ends
   `exported`; the result is served through the W6 `self_hosted` profile) or
   **`openai`** (fine-tuning API: base model from the code-owned
   `OpenAiFineTuningBackend::BASE_MODELS`, API base behind `AllowListedEndpoint`,
   4xx permanent, shape-checked ids, failures recorded with a machine reason).
   Any backend other than `export` requires the tenant in
   `KB_EXPANSION_TRAINING_CONSENTED_TENANTS` — fail-closed. Minimum
   `KB_EXPANSION_TRAINING_MIN_ROWS` (floor 10).
6. **Promote / retire.** A `ready` candidate with a provider id becomes the
   tenant's expansion model only by `promote()` (super-admin + confirm on HTTP,
   `--yes` on CLI); it is then served through the OpenAI provider with its
   `ft:` id (when the profile is `provider`); one promoted model per tenant
   (`uq_kb_exp_models_one_promoted` on PostgreSQL). `retire()` is the rollback; it
   refuses a run still `submitted` / `training` at the provider.

### Surfaces (R44)

- **PHP:** `kb:expansion-learn {status|analyze|apply|reject|train|refresh|promote|retire|prune|nightly}`.
  Scheduler slot `kb_expansion_learn` → `kb:expansion-learn nightly` at
  `50 3 * * *` (`SCHEDULE_KB_EXPANSION_LEARN_*`): prune + analyse every
  (tenant, project) with enough events + poll every provider training job still
  `submitted`/`training` (`refreshPending()`); a silent no-op while the loop is OFF.
- **HTTP:** `/api/admin/kb/expansion-learning/*` in the `role:admin|super-admin`
  group (R32 matrix row): `GET /`, `POST /analyze` (queued,
  `AnalyzeExpansionLearningJob`, 202), `POST /tunings/{id}/apply|reject`,
  `POST /events/{id}/feedback`, `POST /training`, `POST /models/{id}/refresh|promote|retire`.
  `training` and `promote` additionally require super-admin **and**
  `confirm: true`. Admin screen: Knowledge › Quality › *Expansion learning*.
- **MCP:** `KbExpansionLearningTool` — **read-only, documented R44 exception**:
  changing the live expansion, sending data to a training provider, or promoting
  a model is a human decision (ADR 0003's boundary applied to the retrieval
  model). Admin principals only.
- **Storage:** `kb_expansion_events`, `kb_expansion_tunings`,
  `kb_expansion_models` (migration `2026_11_05_000004`), all tenant-aware with
  `tenant_id` first in every composite index (R31).

### Privacy

A new processing purpose: user queries (masked) are stored to improve
retrieval. Recording requires active PII redaction for the project; only the
masked query, the masked variants and the hash are kept, never chunk text;
retention is `KB_EXPANSION_LEARNING_RETENTION_DAYS` (90, the nightly prune);
retention is deployment-wide and deletes by age only (deliberately cross-tenant,
like the other prune commands). Sending the dataset to a training provider is a
transfer to a processor: it needs the tenant's consent and a DPA, recorded by the
operator in `KB_EXPANSION_TRAINING_CONSENTED_TENANTS`. The `export` backend sends
nothing anywhere.

### Honest limits (what W7 does not prove)

- **No GPU training happens here.** `export` stops at a JSONL; `openai` delegates.
- **No real logs exist yet.** Every W7 test runs on synthetic events. The
  weights and examples are only as good as the traffic that produces them.
- **The gate measures the benchmark corpus, not the tenant's corpus.** A
  proposal can pass on benchmark v2 and still be neutral (or worse) on a
  tenant's real documents. That is why auto-apply is a second, separate flag.
- **Expansion must be ON for the gate to say anything** — with
  `KB_QUERY_EXPANSION_ENABLED=false` every gate result is `not_measurable` and
  auto-apply never fires.
- **Signal comes from direct retrieval only** (see I1): with the investigation
  on, chat turns do not expand, so they produce no events.
- Training runs advance only as fast as the nightly poll (or a manual
  `kb:expansion-learn refresh --id=` / HTTP refresh): there is no webhook from
  the provider.
