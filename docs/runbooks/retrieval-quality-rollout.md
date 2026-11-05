# Runbook — rolling out the retrieval-quality capabilities (ADR 0035)

**Audience:** operators. **Premise:** every capability below ships **default-OFF** and is
promoted only on evidence from `kb:benchmark --suite=v2` against a real stack (PostgreSQL +
pgvector + a real embeddings provider). The build environment of the cycle had none of these,
so no default was flipped there.

## 0. Prerequisites

```bash
php artisan migrate                       # 2026_11_05_000001..000003
php artisan kb:benchmark --suite=v2       # record the BASELINE scorecard (storage/app/kb-benchmark/)
```

Keep the baseline JSON: every step below is compared to it, per class, not only in aggregate.

## 1. One capability at a time

| Step | Enable | Then | Watch (per class) | Rollback |
|---|---|---|---|---|
| Websearch FTS syntax | `KB_HYBRID_SEARCH_ENABLED=true KB_FTS_QUERY_SYNTAX=websearch` | benchmark | `identifier`, `hard_negative` up; no class down | `KB_FTS_QUERY_SYNTAX=plain` |
| Contextual embeddings | `KB_EMBEDDING_CONTEXTUAL_INPUT=true` | `kb:reembed-stale --dry-run` (tokens/cost), `kb:reembed-stale`, drain the queue, benchmark | `context_dependent`, `paraphrase` up | unset; `kb:reembed-stale` again (the fingerprint reports the drift both ways) |
| Cross-encoder | `KB_CROSS_RERANK_ENABLED=true` (+ profile endpoint/allow-list) | benchmark | `paraphrase`, `hard_negative` up; **refusal accuracy must not drop** | unset (no data change) |
| Query expansion | `KB_QUERY_EXPANSION_ENABLED=true` | benchmark | `paraphrase`, `short_ambiguous`; latency in `meta.query_expansion` | unset |
| Code ingestion | `KB_CODE_INGESTION_ENABLED=true`, `npm install --prefix tools/code-chunker` | ingest a repository with `--pattern`, benchmark | `code` class | unset (existing code documents stay searchable; `kb:delete` removes them) |

Agent affordances (`KB_MCP_FACETS_ENABLED`, `KB_PATH_CONTEXTS_ENABLED`) change what an agent *sees*,
not ranking; enable them per project and check `kb:facets` / `kb:path-context list`.

## 2. Reading a regression

`kb:search "<query>" --explain` shows, per result, which lists produced it, the RRF
contributions, the heuristic terms and — when the stage ran — the cross-encoder score and the
blend. A degraded stage is visible, never silent: `meta.cross_encoder.status = "unavailable"`,
`meta.query_expansion`, and for code `metadata.chunker` per chunk plus `code_chunker_ok` on the
health check.

## 3. Things that cannot be switched off

The code deny-list, size cap, binary/generated checks and secret redaction have no flag. If a
legitimate file is skipped, rename or relocate it (or request the rule change); do not look for a
switch. Credentials found in source are replaced by `[REDACTED_SECRET]` before storage and embedding —
rotate any credential that was committed, the redaction protects the index, not the repository.
