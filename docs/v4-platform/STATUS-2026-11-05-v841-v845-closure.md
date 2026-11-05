# STATUS — AskMyDocs "Retrieval Quality" cycle closure (W3, W4, W5, W6, W7 + merge with main)

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

## Merge with main (the develop → main consolidation)

`origin/main` received the 2026-11-05 develop → main consolidation
([dossier](../releases/2026-11-05-develop-main/README.md)); it was merged into this branch in
`42506cca`. ADR 0035 records the decisions in the addendum *Integration with the recursive investigation*.

| Deliverable | State |
|---|---|
| `ModelWrittenQueries::run()` scope around the retrieval of `KbInvestigationService` (round queries), `AgentLoop` and `AgentWaveKnowledgeSearch` (filters passed to `retrieve()` unchanged; `RetrievalFilters::withoutExpansion()` for callers building filters); `meta.query_expansion = {status: skipped, reason: model_written_query}` | shipped; zero provider calls asserted |
| main's `$observe` diagnostics wrap `kb.query_expansion`, `kb.rerank`, `kb.cross_encoder` in `KbSearchService::search()` | shipped |
| Explain traces the investigation: `kb:search --investigation`, explain HTTP `investigation=1`, `KbRetrievalExplainTool` `investigation: true` → `meta.investigation` (= `KbInvestigationResult::trace()`); `{status: disabled, flag: KB_RECURSIVE_RETRIEVAL_ENABLED}` when the switch is off | shipped, both states tested |
| `kb:benchmark --path=direct\|investigation` (`BenchmarkRunner::PATH_*`); scorecard `path`, per-row `investigation` | shipped; unknown path rejected |
| `CodeAstChunker` splits a single over-cap line with main's `TextBudgetSplitter` (`metadata.split.part/of`) | shipped |
| Code symbols found by main's exact identifier lookup (`exactIdentifierCandidates`, `pg_trgm` indexes) | asserted |
| Path-contexts admin screen: IT/EN messages, shared `SelectField`, nav under Knowledge › Settings | shipped; Vitest + Playwright updated |

## W6 — qmd parity closure (ADR 0035 addendum)

| Deliverable | State |
|---|---|
| `TopRankBonus` — +`first` (0.05) / +`podium` (0.02) on the **original query's** lists only, both fusion paths; explain `rrf = {base, top_rank_bonus, total}` | shipped (`KB_RRF_TOP_RANK_BONUS_ENABLED`, default OFF) |
| `DocumentBatchReader` — selectors `#ref` / glob / exact path, ≤ 20, byte budget with declared `truncated` / `omitted` / `missing`; `kb:multi-get`, `POST /api/kb/documents/multi` (`token.ability:kb:read`, `throttle:30,1`), `KbMultiGetTool` | shipped (`KB_MULTI_GET_ENABLED`, default OFF; `404 multi_get_disabled`) |
| `DocumentReferenceResolver` — `#` + 6–64 hex prefix of `document_hash`, tenant + ACL scoped, archived excluded, ambiguity = error with candidates; `document.ref` on every search result; `kb:show #ref`; `KbReadDocumentTool` `ref` | shipped (no flag — additive key) |
| `UserQueryDocument` — `lex:` / `vec:` / `hyde:` / `intent:` lines become the expansion, no model call, `meta.query_expansion.status = user_document` | shipped (`KB_QUERY_DOCUMENTS_ENABLED`, default OFF) |
| `kb:search --min-score --all --line-numbers --files --csv --md`; `min_score` / `line_numbers` on explain HTTP + MCP; `min_score` on `KbSearchTool` (`ResultScoreFloor`); CSV formula-neutralised | shipped |
| Self-hosted expansion profile: `KB_QUERY_EXPANSION_PROFILE=provider\|self_hosted`, `SelfHostedExpansionClient`, shared SSRF guard `App\Support\Http\AllowListedEndpoint` (now also used by `HttpCrossEncoderReranker`) | shipped; allow-list test |
| Tests: `QmdParityStagesTest`, `MultiGetAndReferencesTest` | green |

## W7 — expansion learning loop (ADR 0035 addendum)

| Deliverable | State |
|---|---|
| Migration `2026_11_05_000004` — `kb_expansion_events`, `kb_expansion_tunings`, `kb_expansion_models` (tenant-aware, R31; models listed in the architecture tests) | shipped |
| `ExpansionSignalRecorder` — one masked event per expansion that ran, per-type `lists` / `in_top` / `unique_in_top` (the reward); records **only** when PII redaction is active for the project (`ChunkRedactor::isActive()`, fail-closed), variants masked too; suppressed during the gate; never blocks the user path | shipped |
| `ExpansionLearningService::analyze()` — weights `clamp(0.5 + hit rate, 0.25, 1.5)` + ≤ 4 masked few-shot examples; `min_events` / `window_days` | shipped |
| `BenchmarkExpansionTuningGate` (behind `ExpansionTuningGate`) — benchmark v2 baseline vs candidate in the dedicated tenant `system-expansion-gate` (`KB_EXPANSION_LEARNING_GATE_TENANT`); `passed` / `failed` / `not_measurable` (expansion off ⇒ never auto-applied) | shipped |
| Apply / reject (rollback) — one `applied` tuning per (tenant, project) (scope lock, R21; PG partial unique `uq_kb_exp_tunings_one_applied`); every mutation audited in `admin_command_audit`; `ActiveExpansionTuning` (cached, fails safe to neutral) feeds the per-type multipliers to the N-list fusion and the examples to the expansion prompt as delimited user-turn data; tuning id in the expansion cache key | shipped |
| Auto-apply only with `KB_EXPANSION_LEARNING_AUTO_APPLY=true` **and** a passed gate | asserted |
| Training: `export` (JSONL on the private disk, default) and `openai` (fine-tuning API; code-owned `BASE_MODELS`; `AllowListedEndpoint`; consent list `KB_EXPANSION_TRAINING_CONSENTED_TENANTS`, fail-closed; `min_rows`) | shipped; provider faked in tests |
| Human promotion (super-admin + `confirm`) → served through the OpenAI provider with the `ft:` id; one promoted model per tenant (PG `uq_kb_exp_models_one_promoted`); `retire` = rollback (refused while a provider job is `submitted`/`training`; `retired_by`/`retired_at`); dataset write failure → 503 `storage_failed` | shipped |
| Surfaces: `kb:expansion-learn` (10 actions), `/api/admin/kb/expansion-learning/*` (R32 matrix row; `analyze` queued 202 via `AnalyzeExpansionLearningJob`), `KbExpansionLearningTool` (read-only — documented R44 exception), admin screen Knowledge › Quality › Expansion learning (Vitest + `admin-expansion-learning.spec.ts`) | shipped |
| Scheduler slot `kb_expansion_learn` → `kb:expansion-learn nightly` (`50 3 * * *`, `SCHEDULE_KB_EXPANSION_LEARN_*`): prune + analyse + poll pending provider training jobs; no-op while OFF | shipped |
| Flag `KB_EXPANSION_LEARNING_ENABLED` | default OFF (`404 expansion_learning_disabled` / `{disabled: true}`, nothing recorded) |
| Tests: `ExpansionLearningLoopTest` (both flag states, cross-tenant 404, consent, base-model allow-list, super-admin gates) | green |

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
4. **The investigation path in the benchmark.** `kb:benchmark --path=investigation` needs a chat provider and
   a retrieval profile; it was exercised for its contract, never run live here.
5. **The W6 bonus values.** 0.05 / 0.02 are qmd's numbers, not tuned on our corpus with real embeddings.
6. **The W7 loop on real traffic.** No real query log exists yet; every W7 test runs on synthetic events. The
   gate measures the **benchmark** corpus, not a tenant's corpus, and returns `not_measurable` while query
   expansion is off. No GPU training ran (the `export` backend stops at a JSONL; the `openai` backend was
   exercised against a faked API). Expansion signals come only from direct retrieval: with
   `KB_RECURSIVE_RETRIEVAL_ENABLED=true` chat turns do not expand and record nothing.
7. **The self-hosted expansion profile** against a real vLLM / Ollama endpoint (faked in tests).

## Operator steps

```bash
php artisan migrate                                  # 2026_11_05_000001..000004
npm install --prefix tools/code-chunker              # ingestion workers only, if you want tree-sitter
# per capability, in this order, measuring each with kb:benchmark --suite=v2
# (and --path=investigation where a chat provider + retrieval profile exist):
#   KB_FTS_QUERY_SYNTAX=websearch   KB_EMBEDDING_CONTEXTUAL_INPUT=true (+ kb:reembed-stale)
#   KB_RRF_TOP_RANK_BONUS_ENABLED=true
#   KB_CROSS_RERANK_ENABLED=true    KB_QUERY_EXPANSION_ENABLED=true
#     (optional: KB_QUERY_EXPANSION_PROFILE=self_hosted + _SELF_HOSTED_URL/_MODEL/_ALLOWED_HOSTS)
#   KB_CODE_INGESTION_ENABLED=true  (+ KB_CODE_CHUNKER_DRIVER=auto|tree_sitter)
# agent affordances, per project (no ranking change):
#   KB_MULTI_GET_ENABLED=true  KB_QUERY_DOCUMENTS_ENABLED=true
#   KB_MCP_FACETS_ENABLED=true KB_PATH_CONTEXTS_ENABLED=true
# learning loop — only after query expansion is ON and has served real traffic:
#   KB_EXPANSION_LEARNING_ENABLED=true   (records; nightly analyses; humans apply)
#   KB_EXPANSION_LEARNING_AUTO_APPLY=true (later, only if gate results have been trustworthy)
#   KB_EXPANSION_TRAINING_CONSENTED_TENANTS=<tenant,…> (only with recorded consent + DPA, for --backend=openai)
```

The step-by-step procedure and rollbacks are in the
[rollout runbook](../runbooks/retrieval-quality-rollout.md).

**Tags:** assign the final version numbers (see the note at the top) and tag as the earlier cycles'
STATUS documents describe; a session credential that cannot push tag refs cannot do it.

## Inherited from main (not caused by this PR)

The consolidation's own [VALIDATION](../releases/2026-11-05-develop-main/VALIDATION.md) records four red
gates that exist identically on the original develop. They arrive with the merge of main and are **not**
introduced by the retrieval-quality cycle; they are listed here so the PR's CI reading is honest:

1. **Full PHP suite stops at `FreshdeskCaseStudyTest`** (4 293 / 6 733 tests): the lock pins
   `padosoft/askmydocs-connector-freshdesk` **1.0.0**, which does not contain
   `CaseStudies\CaseStudyGeneratorContract` used by `app/Connectors/Freshdesk/OpenRouterCaseStudyGenerator.php`.
   Fix belongs to the package release + lock (merger, BUILD-LOCK).
2. **`GraphExecutorNotAdoptedTest`** (Architecture suite): `OperationNode.php → FlowNodeHandler` adopts the graph
   executor the contract still forbids until the engine-tables tenancy decision is closed.
3. **`scripts/verify-e2e-real-data.sh`**: five findings in `frontend/e2e/mcp-project-connection.spec.ts:66` and
   `frontend/e2e/pact-auth-consent.spec.ts:14,15,17,31` (internal-route interception without the R13 marker).
4. **FinOps migration rollback on SQLite**: `2026_08_25_000002_add_invocation_id_to_ai_finops_usage_ledger_table`
   cannot drop `invocation_id` while its index exists; the PostgreSQL rollback cycle is still to be run.

## Deferred (documented, not forgotten)

- A dedicated GitHub/GitLab connector for repositories (ADR §D7 "Deferred").
- Symbol-level graph edges from code (`imports` / calls → `kb_edges`).
- A long-lived helper worker mode, if per-file start-up ever dominates.
- A cross-encoder score as a refusal signal (needs its own calibration set).
- A pure-PHP `nikic/php-parser` fast path for PHP files.
- W7: a DB-backed, audited per-tenant training-consent record (today an operator env list); a tenant-corpus
  gate (today benchmark v2); a provider webhook for training runs (today the nightly poll or `kb:expansion-learn refresh`).
