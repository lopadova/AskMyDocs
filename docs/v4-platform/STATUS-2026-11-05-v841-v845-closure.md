# STATUS — AskMyDocs "Retrieval Quality" cycle closure (W3, W4, W5)

**Cycle:** provisional labels v8.41 → v8.45 ([ADR 0035](../adr/0035-v841-retrieval-quality-cycle.md),
[plan](PLAN-v8.41-retrieval-quality.md)). W1 and W2 are recorded in
[their own closure](STATUS-2026-11-05-v841-v842-w1-w2-closure.md).
**Branch / PR:** `claude/askmydocs-v8.36-document-intelligence-xvkopq` → `main`,
[PR #533](https://github.com/lopadova/AskMyDocs/pull/533) — one PR for the whole cycle, by decision of the
product owner; assigned for review only after W5. **Closed:** 2026-11-05.

> **Version labels are provisional.** The `v8.41.0` tag already belongs to the Enterprise roadmap E02.01
> (`ActionIntent`, PR #520). The cycle's code, ADR and plan keep the v8.41→v8.45 labels they were written
> under; the operator assigns the real numbers when the PR is merged.

## W3 — cross-encoder stage (ADR §D4)

| Deliverable | State |
|---|---|
| `CrossEncoderReranker` contract, `FakeCrossEncoderReranker`, `HttpCrossEncoderReranker`, `CrossEncoderManager`, `CrossEncoderStage` | shipped |
| Profiles `self_hosted` (**default**) and `api`, selected by `KB_CROSS_RERANK_PROFILE`; endpoint + exact host allow-list per profile (a tenant/admin setting can only choose from the code-owned list) | shipped |
| `CrossEncoderBlender` — `final = w(rank)·ĥ + (1−w)·c·(1−status penalty) + mention bonus`, tiers 0.75 / 0.60 / 0.40; the mention boost is a flat +1.0 precedence the model cannot bury | shipped, 38 unit tests |
| `rerank_score` / `vector_score` are **never modified** — the refusal gate reads exactly what it read before | asserted |
| `Reranker` is called with `max(limit, candidates)` only while the stage is ON; OFF is byte-identical | asserted (R43) |
| FinOps: `CrossRerankCallMeter`, purpose tag `cross_rerank`; PII redaction before egress; a candidate-count and payload cap | shipped |
| Failure → `meta.cross_encoder = {status: "unavailable", reason}` and the heuristic order stands (R14: visible, never silent) | shipped |
| Flag `KB_CROSS_RERANK_ENABLED` | default OFF |

## W4 — expansion and agent affordances (ADR §D5, §D6)

| Deliverable | State |
|---|---|
| `QueryExpansionService` + strict `QueryExpansionParser` (typed `lex`/`vec`/`hyde`; malformed, oversize, wrong-shape and duplicate output rejected); weighted N-list RRF; strong-signal skip; `intent` never sent to the expansion model; chunks surfaced only by an expansion list get `vector_score` recomputed against the **original** query embedding | shipped (`KB_QUERY_EXPANSION_ENABLED`, default OFF) |
| Facets — `GET /api/kb/facets` (`token.ability:kb:read`), `kb:facets`, `KbFacetsTool` | shipped (`KB_MCP_FACETS_ENABLED`, default OFF; `404 facets_disabled`) |
| Line-range reads — `from_line`/`max_lines` on `KbReadDocumentTool`, `start_line`/`end_line` on `KbReadChunkTool`, `kb:show`, the document preview endpoint | shipped, capped by `KB_LINE_READ_MAX_LINES` |
| Did-you-mean — `SimilarDocumentFinder` (tenant- and ACL-scoped, `LikeEscaper`) on a missed slug | shipped |
| Path contexts — table `kb_path_contexts` (tenant-aware, R31), `PathContextService` (segment-aware chain, audited with a hash only), HTTP CRUD, `kb:path-context`, `KbPathContextsTool` (read-only), a `<path-contexts>` data block in the prompt, `path_context` on citations, an admin screen with Playwright coverage | shipped (`KB_PATH_CONTEXTS_ENABLED`, default OFF) |
| `intent` on chat, `kb:search`, `KbSearchTool`, `KbSearchByProjectTool`, explain | shipped |
| R32 matrix rows + `role-access.spec.ts` rows | added |

## W5 — source code as a content class (ADR §D7)

| Deliverable | State |
|---|---|
| Spike note + as-built deviations appended to ADR 0035 | done |
| `SourceType::CODE`, `CodeLanguageMap` (18 extensions → 14 languages), `text/x-<language>` tokens in `config/kb-pipeline.php` (parity test) | shipped |
| `CodeIngestionPolicy` — master switch, non-overridable deny-list, size cap, binary and generated-file checks; operator globs only **add** | shipped |
| Entry points: `kb:ingest-folder --pattern=…` (default pattern never includes code), `POST /api/kb/ingest` (`text/x-<lang>`; refused files `status: "skipped"`), GitHub Action input `code_globs` | shipped, both flag states tested |
| Admission re-applied to the bytes in `ParseMarkdownStep`, `DocumentIngestor::ingest` and on the folder fan-out (deterministic refusals do not burn job retries) | shipped |
| `SecretRedactor` in `CodePassthroughConverter` (before the artifact exists), at the `ChunkRedactor` seam, and before the raw file is written by the API | shipped; "the secret never reaches storage or the embeddings fake" asserted on three paths |
| `tools/code-chunker` (Node, `web-tree-sitter` 0.25.10 + `tree-sitter-wasms` 0.1.13): 11 grammars, JSON break points on stdout, never code | shipped |
| `TreeSitterBreakPointDriver` (Symfony `Process`, argument array, stdin, timeout, output cap, cached health probe), `HeuristicBreakPointDriver` (14 languages), `BreakPointDriverResolver` (`auto` / `tree_sitter` / `heuristic`) | shipped; timeout / crash / garbage / oversize ⇒ heuristic under `auto`, loud failure under `tree_sitter` |
| `CodeAstChunker` — scored break points, look-back window with squared-distance decay, straddling declarations kept whole under the hard cap, tail overlap on a hard cut, tiny-tail merge; idempotent | shipped; `PipelineRegistryChunkerMutexTest` extended |
| Code embedding header (`file \| symbol \| lang \| code`) always used for code chunks | shipped |
| Full text: `idx_chunks_fts_code` partial `simple` index (migration `2026_11_05_000003`) + a code-only second pass merged by chunk id | shipped; index plan verified on PostgreSQL 16 |
| Observability: `code_ingestion` block (status command, HTTP, MCP) and `code_chunker_ok` / `code_chunker` on the health check | shipped, both states tested |
| Architecture test: every `Process` in the subsystem is built from an argument array; no shell primitives | `CodeProcessArgvTest` |
| Benchmark: the `code` class is ingested and scored when the flag is on, skipped-and-reported when off | shipped |
| CI: `npm install --prefix tools/code-chunker` so the real-helper tests run | `tests.yml` |
| Doc-site: `ingesting-source-code.mdx` (registered), cross-links, configuration / CLI / API / MCP rows | shipped |

## Verification

Run on the build machine (PHP 8.3.6, SQLite in-memory, Node 22):

- New and touched suites for the whole cycle — green (W3 38 unit tests; W4 `KbSearchServiceStagesTest`,
  `KbPathContextSurfacesTest`, `KbAgentSurfacesTest`, `PathContextServiceTest`; W5 the suites under
  `tests/Feature/Kb/Code` and `tests/Unit/Services/Kb/Code`, `CodeProcessArgvTest`, the gate tests).
- Architecture suite green (`TenantIdMandatoryTest` / `TenantReadScopeTest` list `KbPathContext`;
  `NoBackslashLikeEscapeTest`; the chunker mutex).
- GitHub Action: the collect step ran against a temporary git repository in full-sync and diff modes, with
  and without `code_globs`; the POST step ran against a fake `curl` and produced the right
  `mime_type` per file.
- `npm run security:rules` green.
- The regression run over `tests/Feature/{Kb,Flow,Api,Admin,Commands,Console,Jobs,Mcp,Security,Benchmark}`,
  `tests/Architecture` and `tests/Unit`: see the result recorded in the PR description.

### Not measured here (read this before enabling anything)

1. **Retrieval quality on real models.** Benchmark v2 runs on the deterministic stub embedder; there is no
   embeddings provider, no pgvector and no cross-encoder in this environment. The benchmark therefore proves
   the machinery, the per-class scorecard and the regression gate — **not** that contextual embeddings,
   the cross-encoder, expansion or code chunking improve answers. Per ADR acceptance criterion 2, every flag
   stays default-OFF until the live comparison exists. Recipe: see the W1/W2 closure and run, per flag,
   `php artisan kb:benchmark --suite=v2` before and after on a pgvector instance.
2. **The PostgreSQL full-text paths end to end.** The partial-index plan was verified on PostgreSQL 16, but
   the pgvector extension is not installed here, so the PG-only branches of `KbSearchService` (websearch
   syntax, the code-only second pass) are covered by unit tests and a plan check, not by a real query. The
   Postgres E2E job is the gate.
3. **A 2 000-file repository.** The helper was timed on 300 files (≈ 120 ms each including start-up) and
   the 2 000-file figure is an extrapolation.

## Operator steps

```bash
php artisan migrate                                  # 2026_11_05_000001..000003
npm install --prefix tools/code-chunker              # ingestion workers only, if you want tree-sitter
# per capability, in this order, measuring each with kb:benchmark --suite=v2:
#   KB_FTS_QUERY_SYNTAX=websearch   KB_EMBEDDING_CONTEXTUAL_INPUT=true (+ kb:reembed-stale)
#   KB_CROSS_RERANK_ENABLED=true    KB_QUERY_EXPANSION_ENABLED=true
#   KB_CODE_INGESTION_ENABLED=true  (+ KB_CODE_CHUNKER_DRIVER=auto|tree_sitter)
```

**Tags:** assign the final version numbers (see the note at the top) and tag as the earlier cycles'
STATUS documents describe; a session credential that cannot push tag refs cannot do it.

## Deferred (documented, not forgotten)

- A dedicated GitHub/GitLab connector for repositories (ADR §D7 "Deferred").
- Symbol-level graph edges from code (`imports` / calls → `kb_edges`).
- A long-lived helper worker mode, if per-file start-up ever dominates.
- A cross-encoder score as a refusal signal (needs its own calibration set).
- A pure-PHP `nikic/php-parser` fast path for PHP files.
