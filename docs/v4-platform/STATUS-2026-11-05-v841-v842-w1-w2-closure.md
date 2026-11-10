# STATUS — AskMyDocs v8.41 + v8.42 — Retrieval quality, W1 and W2

**Cycle:** v8.41 → v8.45 "Retrieval Quality" ([ADR 0035](../adr/0035-v841-retrieval-quality-cycle.md),
[plan](PLAN-v8.41-retrieval-quality.md)). **Branch / PR:**
`claude/askmydocs-v8.36-document-intelligence-xvkopq` → `main`,
[PR #533](https://github.com/lopadova/AskMyDocs/pull/533) (one PR for the whole cycle, by
decision of the product owner; the PR is assigned for review only after W5).
**Closed:** 2026-11-05.

## W1 — measure first (v8.41)

| Deliverable | State |
|---|---|
| Benchmark v2 — `resources/benchmark/v2/` (40 corpus files: 34 markdown incl. 15 canonical + 2 rejected-approach, 6 source files, plus a generated PDF and DOCX; 90 labelled queries in 7 classes (80 non-code + 10 code), IT + EN) | shipped |
| `kb:benchmark --suite=v1\|v2`, per-class breakdown (`by_class`), `skipped_queries`, v2-specific gate | shipped |
| Gate in CI | `BenchmarkV2Test` runs `--stub --suite=v2 --gate` on every PHPUnit run, and proves the floor is real (a raised floor fails the gate) |
| `explain` — `RetrievalExplainService` over the tri-surface: `kb:search --explain`, `GET /api/admin/kb/retrieval/explain`, `KbRetrievalExplainTool` | shipped, default OFF on HTTP/MCP |
| Lexical syntax — `FtsQueryBuilder`, `KB_FTS_QUERY_SYNTAX=plain\|websearch` | shipped, default `plain` |
| R32 matrix row | `/api/admin/kb/retrieval/explain` → `admin`, `super-admin` |

### Recorded baseline (stub embedder, SQLite, 80 non-code queries, k = 5)

| metric | v2 baseline | committed floor (−0.02) |
|---|---|---|
| nDCG@5 | 0.6044 | 0.58 |
| MRR | 0.6405 | 0.62 |
| citation-precision | 0.7246 | 0.70 |
| refusal-accuracy | 0.875 | 0.85 |

Per class (nDCG@5): `context_dependent` 0.966 · `short_ambiguous` 0.832 · `identifier` 0.677 ·
`hard_negative` 0.532 · **`paraphrase` 0.283**. v1 scores ≈ 0.997 on the same code: v2 can fail,
and fails exactly where the later workstreams aim (paraphrase, hard negatives).

The 10 `code` queries are **skipped and reported** (`skipped_queries`) while
`KB_CODE_INGESTION_ENABLED` is off, never scored as a false zero; W5 turns them on.

### Decision gate — not taken

ADR 0035 §D3 promotes `websearch` and/or `KB_HYBRID_SEARCH_ENABLED=true` to default **only** on
benchmark-v2 evidence. The full-text branch is PostgreSQL-only; the deterministic stub run on
SQLite cannot exercise it. **The defaults therefore stay `plain` / hybrid off**, and the LIVE
comparison (pgvector + FTS on, `websearch` vs `plain`, real embeddings) is an operator step:

```bash
KB_HYBRID_SEARCH_ENABLED=true KB_FTS_QUERY_SYNTAX=websearch php artisan kb:benchmark --suite=v2
```

## W2 — contextual embeddings + fingerprint (v8.42)

| Deliverable | State |
|---|---|
| `EmbeddingInputTemplate` — one composition path for `DocumentIngestor` **and** the Flow `EmbedChunksStep` | shipped |
| Header fragments (title, path, headings) PII-redacted via `ChunkRedactor::redactTexts()` before the first embedding | shipped, tested |
| `knowledge_chunks.embedding_fingerprint` (nullable, indexed with `tenant_id`) written on every chunk save | shipped (+ SQLite test mirror) |
| `kb:reembed-stale {--project} {--tenant} {--batch} {--dry-run}` via `ReembedStaleService` (reuses `ReembedDocumentJob`) | shipped |
| `stale_embeddings` additive key on `ingestion:status`, `GET /api/admin/ingestion/queue`, `KbIngestionStatusTool` | shipped |
| Flag `KB_EMBEDDING_CONTEXTUAL_INPUT` | shipped, default OFF; OFF is byte-identical (asserted) |

### Deviations from the ADR (recorded, not hidden)

- **Dimensions are not part of the fingerprint.** provider + model already determine them, and
  keeping them out lets the *active* fingerprint be computed without a provider call (so
  `--dry-run` and the status block cost nothing). ADR §D2 text updated.
- **Cost estimate** comes from an optional `KB_EMBEDDING_PRICE_PER_MILLION_TOKENS`, not the FinOps
  price table; unset, the dry run reports tokens and no cost.
- **The explain endpoint is `GET`**, not `POST` (read-only, keeps it inside the matrix's GET sweep).

### Not measured here

ADR 0035 acceptance #2 asks for LIVE improvement numbers from a real-embeddings run. No embeddings
provider or pgvector is available in this build environment, so **no default was flipped on
evidence it could not produce**. `KB_EMBEDDING_CONTEXTUAL_INPUT` stays OFF; the recipe to measure it:

```bash
php artisan kb:benchmark --suite=v2                                   # baseline
KB_EMBEDDING_CONTEXTUAL_INPUT=true php artisan kb:reembed-stale --dry-run
KB_EMBEDDING_CONTEXTUAL_INPUT=true php artisan kb:reembed-stale       # then run the queue
KB_EMBEDDING_CONTEXTUAL_INPUT=true php artisan kb:benchmark --suite=v2
```

`context_dependent` and `paraphrase` should improve; no class may regress beyond tolerance.
