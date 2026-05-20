<?php

return [
    'embeddings_dimensions' => env('KB_EMBEDDINGS_DIMENSIONS', 1536),
    'default_min_similarity' => env('KB_MIN_SIMILARITY', 0.30),
    'default_limit' => env('KB_DEFAULT_LIMIT', 8),

    /*
    |--------------------------------------------------------------------------
    | Embedding Cache
    |--------------------------------------------------------------------------
    |
    | When enabled, embeddings are cached in a DB table keyed by SHA-256 hash
    | of the input text + provider + model. Re-ingesting unchanged documents
    | or repeating the same search query won't call the API again.
    |
    */

    'embedding_cache' => [
        'enabled' => env('KB_EMBEDDING_CACHE_ENABLED', true),

        /*
        | Retention for the scheduled prune job (kb:prune-embedding-cache).
        | Entries whose `last_used_at` is older than this are deleted.
        */
        'retention_days' => (int) env('KB_EMBEDDING_CACHE_RETENTION_DAYS', 30),

        /*
        | v4.2/sub-PR 3d — kb.prune-embedding-cache Flow inserts a
        | conditional approval gate when the projected eviction count
        | exceeds this threshold. Keeps small daily evictions flowing
        | without operator intervention while surfacing large
        | accidental sweeps (e.g. a misconfigured retention_days) for
        | review. Set to 0 (or any negative) to disable the gate
        | entirely (every prune runs without approval).
        */
        'approval_threshold' => (int) env('KB_EMBEDDING_CACHE_APPROVAL_THRESHOLD', 5000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Hybrid Search (Vector + Full-Text)
    |--------------------------------------------------------------------------
    |
    | When enabled, pgvector semantic results are merged with PostgreSQL
    | full-text search (tsvector/tsquery) via Reciprocal Rank Fusion (RRF).
    |
    | This catches exact terms (product codes, legal refs, acronyms) that
    | pure semantic search may miss.
    |
    */

    'hybrid_search' => [
        'enabled' => env('KB_HYBRID_SEARCH_ENABLED', false),
        'fts_language' => env('KB_FTS_LANGUAGE', 'italian'),
        'rrf_k' => env('KB_RRF_K', 60),
        'semantic_weight' => env('KB_HYBRID_SEMANTIC_WEIGHT', 0.70),
        'fts_weight' => env('KB_HYBRID_FTS_WEIGHT', 0.30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Reranking
    |--------------------------------------------------------------------------
    |
    | When enabled, KbSearchService over-retrieves candidates (limit × multiplier)
    | and the Reranker fuses vector similarity with keyword/heading relevance
    | before returning the final top-K results.
    |
    | Score composition (intentional, NOT a single normalised sum):
    |
    |   base_score   = vector_weight × cos_sim                  // 0..1
    |                + keyword_weight × kw_score                // 0..1
    |                + heading_weight × heading_score           // 0..1
    |                + canonical_base × canonical_signal        // 0..1
    |
    |   The four base signals are WEIGHTED to sum to 1.0 with the
    |   shipped defaults (0.55 + 0.25 + 0.05 + 0.15 implicit on the
    |   canonical_base of (1 - vec - kw - heading)). That sub-score is
    |   normalised and represents the comparative dimension.
    |
    |   additive_layer
    |                = tag_overlap_weight        × tag_overlap     // 0..1
    |                + preamble_match_weight     × preamble_match  // 0..1
    |                + recency_weight            × recency         // 0..1
    |                + status_active_weight      × status_active   // 0/1
    |                + canonical_priority_boost (~0..0.30)
    |                - canonical_status_penalty (~0..0.60)
    |
    |   rerank_score = base_score + additive_layer
    |
    | The additive Layer-4 signals are NOT part of the normalised
    | sub-score — they are intentionally additive boosters / penalties
    | on top of it. With shipped defaults their MAX positive
    | contribution is 0.14 (= 0.05 + 0.05 + 0.02 + 0.02) and the
    | canonical priority boost adds up to another ~0.30, so
    | `rerank_score` CAN exceed 1.0 — the pathological ceiling is
    | ~1.44 when every signal fires at max. This is by design: the
    | additive layer is a separate dimension that says "this chunk
    | gets a thumb on the scale", not "this chunk's normalised score
    | is higher". Sorting still works (relative order is preserved)
    | and downstream consumers MUST treat `rerank_score` as
    | comparable-within-result-set, not as a probability.
    |
    | Tune via env at deploy time when shipping a regression-tested
    | weight refresh.
    |
    */

    'reranking' => [
        'enabled' => env('KB_RERANKING_ENABLED', true),
        'candidate_multiplier' => env('KB_RERANK_CANDIDATE_MULTIPLIER', 3),

        // Base 4 signals — these (plus the implicit canonical_base
        // remainder) are the normalised sub-score that sums to 1.0.
        'vector_weight' => env('KB_RERANK_VECTOR_WEIGHT', 0.55),
        'keyword_weight' => env('KB_RERANK_KEYWORD_WEIGHT', 0.25),
        'heading_weight' => env('KB_RERANK_HEADING_WEIGHT', 0.05),

        // v4.5/W5.5 source-aware retrieval-boost signals. ADDITIVE on
        // top of the normalised base score (NOT part of the 1.0 sum).
        // Their max combined contribution is 0.14 with shipped
        // defaults; combined with the canonical priority boost
        // (~0..0.30, see `canonical.priority_weight` below) the
        // pathological ceiling for `rerank_score` is ~1.44. Honest
        // documentation: scores above 1.0 are expected and intentional.
        'tag_overlap_weight'    => (float) env('KB_RERANK_TAG_OVERLAP_WEIGHT', 0.05),
        'preamble_match_weight' => (float) env('KB_RERANK_PREAMBLE_WEIGHT', 0.05),
        'recency_weight'        => (float) env('KB_RERANK_RECENCY_WEIGHT', 0.02),
        'status_active_weight'  => (float) env('KB_RERANK_STATUS_WEIGHT', 0.02),
    ],

    'chunking' => [
        'target_tokens' => env('KB_CHUNK_TARGET_TOKENS', 512),
        'hard_cap_tokens' => env('KB_CHUNK_HARD_CAP_TOKENS', 1024),
        'overlap_tokens' => env('KB_CHUNK_OVERLAP_TOKENS', 64),
    ],

    /*
    |--------------------------------------------------------------------------
    | Background ingestion (queue)
    |--------------------------------------------------------------------------
    |
    | Controls how KbIngestFolderCommand and POST /api/kb/ingest dispatch
    | their per-document jobs. The queue name is honoured by every driver
    | (sync, database, redis). The default project_key is used when the
    | command/API call omits one — handy for single-tenant deployments.
    |
    */

    'ingest' => [
        'queue' => env('KB_INGEST_QUEUE', 'kb-ingest'),
        'default_project' => env('KB_INGEST_DEFAULT_PROJECT', 'default'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Document deletion
    |--------------------------------------------------------------------------
    |
    | When `soft_delete` is true (default), kb:delete marks documents as
    | deleted (deleted_at timestamp + cascade soft-delete of chunks), but
    | the original file and the row itself are retained. The scheduled
    | `kb:prune-deleted` command purges soft-deleted documents older than
    | `retention_days`, also removing the original file on the KB disk.
    |
    | When `soft_delete` is false, kb:delete purges immediately (hard
    | delete of the document + chunks + physical file).
    |
    | The API endpoint DELETE /api/kb/documents and the GitHub Action
    | honor the same defaults but accept an explicit `force` flag.
    |
    */

    'deletion' => [
        'soft_delete' => env('KB_SOFT_DELETE_ENABLED', true),
        'retention_days' => (int) env('KB_SOFT_DELETE_RETENTION_DAYS', 30),
    ],

    'sources' => [
        /*
        | Laravel filesystem disk used to read KB markdown files.
        | Change to 's3' (and provide AWS_* env) to serve docs from S3.
        | See config/filesystems.php for the disk definitions.
        */
        'disk' => env('KB_FILESYSTEM_DISK', 'kb'),

        /*
        | Optional path prefix applied to every ingested path. Useful when
        | multiple projects share the same bucket: set to e.g. "project-a/".
        */
        'path_prefix' => env('KB_PATH_PREFIX', ''),

        /*
        | Legacy absolute filesystem root. Kept for backward compatibility
        | with setups that don't use disks. Prefer KB_FILESYSTEM_DISK.
        */
        'markdown_root' => env('KB_MARKDOWN_ROOT'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Canonical knowledge layer (OmegaWiki-inspired compilation)
    |--------------------------------------------------------------------------
    |
    | Canonical markdown has YAML frontmatter declaring `type`, `status`,
    | `slug`, `id` (business id). When enabled, DocumentIngestor parses the
    | frontmatter and populates the canonical columns on knowledge_documents
    | (doc_id, slug, canonical_type, canonical_status, is_canonical, ...).
    | Non-canonical markdown (no frontmatter) continues to ingest as before.
    |
    | Reranker applies a small boost based on retrieval_priority and
    | status-based penalties for superseded/deprecated/archived docs.
    |
    */

    'canonical' => [
        'enabled' => env('KB_CANONICAL_ENABLED', true),
        'default_type' => env('KB_CANONICAL_DEFAULT_TYPE', null),
        'priority_weight' => (float) env('KB_CANONICAL_PRIORITY_WEIGHT', 0.003),
        'superseded_penalty' => (float) env('KB_CANONICAL_SUPERSEDED_PENALTY', 0.40),
        'deprecated_penalty' => (float) env('KB_CANONICAL_DEPRECATED_PENALTY', 0.40),
        'archived_penalty' => (float) env('KB_CANONICAL_ARCHIVED_PENALTY', 0.60),
        'audit_enabled' => env('KB_CANONICAL_AUDIT_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Knowledge graph (wikilink-derived, project-scoped)
    |--------------------------------------------------------------------------
    |
    | At retrieval time, after the base vector+FTS+Reranker pipeline returns
    | the top-K chunks, GraphExpander walks the wikilink graph 1 hop (by
    | default) and pulls in the best chunk of each neighbor document. No-op
    | when no canonical docs / wikilinks exist.
    |
    */

    'graph' => [
        'expansion_enabled' => env('KB_GRAPH_EXPANSION_ENABLED', true),
        'expansion_hops' => (int) env('KB_GRAPH_EXPANSION_HOPS', 1),
        'expansion_max_nodes' => (int) env('KB_GRAPH_EXPANSION_MAX_NODES', 20),
        'expansion_edge_types' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('KB_GRAPH_EXPANSION_EDGE_TYPES', 'depends_on,implements,decision_for,related_to,supersedes'))
        ))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Anti-repetition memory (rejected-approach injection)
    |--------------------------------------------------------------------------
    |
    | When the user's query correlates (cosine >= min_similarity) with one or
    | more documents of type "rejected-approach", those docs are injected into
    | the prompt under a clearly-labeled block so the LLM does NOT re-propose
    | already-dismissed options.
    |
    */

    'rejected' => [
        'injection_enabled' => env('KB_REJECTED_INJECTION_ENABLED', true),
        'injection_max_docs' => (int) env('KB_REJECTED_INJECTION_MAX_DOCS', 3),
        'min_similarity' => (float) env('KB_REJECTED_MIN_SIMILARITY', 0.45),
    ],

    /*
    |--------------------------------------------------------------------------
    | Counterfactual mini-retrieval (v8.0/W3.4)
    |--------------------------------------------------------------------------
    |
    | "If I had filtered by Y you would have cited Z" — runs a focused
    | mini-retrieval against up to N other projects the calling user
    | has membership in, so the chat UI can surface
    | `meta.counterfactual = [{project_key, top_chunks: [...]}]`
    | panels.
    |
    | RBAC strict: the candidate project set is derived ONLY from
    | `project_memberships` rows scoped to the calling user's
    | (tenant_id, user_id) pair. A project the user has no membership
    | in NEVER surfaces — see CounterfactualServiceTest.
    |
    | All knobs are env-tunable; defaults match ADR 0014 §C.3.
    */
    'counterfactual' => [
        'enabled' => (bool) env('KB_COUNTERFACTUAL_ENABLED', true),
        'max_neighbors' => (int) env('KB_COUNTERFACTUAL_MAX_NEIGHBORS', 3),
        'per_project_limit' => (int) env('KB_COUNTERFACTUAL_PER_PROJECT_LIMIT', 5),
        'min_similarity' => (float) env('KB_COUNTERFACTUAL_MIN_SIMILARITY', 0.25),
        'cache_ttl_seconds' => (int) env('KB_COUNTERFACTUAL_CACHE_TTL_SECONDS', 3600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Anti-hallucination refusal (v3.0+)
    |--------------------------------------------------------------------------
    |
    | Deterministic short-circuit: if the retrieved primary chunks don't
    | meet a similarity floor (`min_chunk_similarity`) and a count
    | (`min_chunks_required`), the chat endpoint refuses BEFORE calling the
    | LLM and returns `refusal_reason: 'no_relevant_context'` with a
    | confidence of 0 and an empty citations array. This eliminates the
    | hallucination class where the model tries to answer questions it
    | has zero grounding for.
    |
    | The threshold is intentionally set above `default_min_similarity`
    | (0.30) — the search step over-retrieves, but the refusal step
    | requires evidence strong enough to stake an answer on.
    |
    */

    'refusal' => [
        'min_chunk_similarity' => (float) env('KB_REFUSAL_MIN_SIMILARITY', 0.45),
        'min_chunks_required' => (int) env('KB_REFUSAL_MIN_CHUNKS', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | Promotion pipeline (raw -> curated -> canonical)
    |--------------------------------------------------------------------------
    |
    | POST /api/kb/promotion/{suggest|candidates|promote} endpoints let Claude
    | skills propose and validate canonical drafts; human-gated step `promote`
    | is the only one that writes markdown to the KB disk. Each canonical type
    | has a conventional folder under the KB root.
    |
    */

    'promotion' => [
        'enabled' => env('KB_PROMOTION_ENABLED', true),
        'path_conventions' => [
            'decision' => 'decisions',
            'module-kb' => 'modules',
            'runbook' => 'runbooks',
            'standard' => 'standards',
            'incident' => 'incidents',
            'integration' => 'integrations',
            'domain-concept' => 'domain-concepts',
            'rejected-approach' => 'rejected',
            'project-index' => '.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-project disk override
    |--------------------------------------------------------------------------
    |
    | Map `project_key => disk_name` to route specific projects to dedicated
    | disks. Falls back to `canonical_disk` (below) for projects not listed.
    |
    | Env format: KB_PROJECT_DISKS='{"hr-portal":"kb-hr","legal-vault":"kb-legal"}'
    */
    'project_disks' => env('KB_PROJECT_DISKS') ? json_decode((string) env('KB_PROJECT_DISKS'), true) : [],

    /*
    |--------------------------------------------------------------------------
    | Raw vs canonical pipeline disks
    |--------------------------------------------------------------------------
    |
    | OmegaWiki-inspired raw → curated → canonical pipeline. `raw_disk` holds
    | unprocessed PDFs/Word/notes; `canonical_disk` (the existing "kb" disk)
    | holds promoted markdown ready for indexing.
    */
    'raw_disk' => env('KB_RAW_DISK', 'kb-raw'),
    'canonical_disk' => env('KB_FILESYSTEM_DISK', 'kb'),

    /*
    |--------------------------------------------------------------------------
    | PII redaction (W4.1 — padosoft/laravel-pii-redactor v1.1+ integration)
    |--------------------------------------------------------------------------
    |
    | Wires AskMyDocs's chat persistence + embedding cache + insights surface
    | through the Padosoft PII redactor package. The package config itself
    | (detectors, packs, NER drivers, token store) is published separately
    | via `php artisan vendor:publish --tag=pii-redactor-config`. The keys
    | below control how AskMyDocs INTEGRATES the redactor into its hot paths.
    |
    | Default: every integration knob is OFF. Hosts opt in by flipping the
    | corresponding env var to true. v3 hosts upgrading to v4.1 see zero
    | behaviour change until they explicitly enable redaction.
    |
    | See docs/v4-platform/INTEGRATION-ROADMAP-sister-packages.md for the
    | per-touch-point integration plan.
    |
    */
    'pii_redactor' => [
        'enabled' => (bool) env('KB_PII_REDACTOR_ENABLED', false),

        /*
        | Chat persistence — when true, the `redact-chat-pii`
        | middleware (`App\Http\Middleware\RedactChatPii`, registered
        | in `bootstrap/app.php` and bound to
        | `POST /conversations/{conversation}/messages` sync + SSE
        | by `routes/web.php`) runs `RedactorEngine::redact()` on the
        | request `content` field BEFORE the controller sees it.
        | Whatever `MessageController` / `MessageStreamController`
        | persists into `chat_logs.question` / `messages.content`
        | is therefore the redacted form — so the LLM and the
        | persisted columns ship redacted in lock-step. The token
        | map (when the active strategy is `tokenise`) lives in
        | `pii_token_maps` for operator-driven detokenisation via
        | the `chatDetokenize` action below.
        */
        'persist_chat_redacted' => (bool) env('KB_PII_REDACT_PERSIST', false),

        /*
        | Embedding cache — when true, `EmbeddingCacheService::generate()`
        | runs `RedactorEngine::redact($text, MaskStrategy)` BEFORE the
        | SHA-256 hash that keys the cache row AND BEFORE the text
        | reaches the embedding provider's HTTP call. The supported
        | embedding providers are OpenAI, Gemini, and Regolo (Anthropic
        | is intentionally NOT an embedding provider —
        | `AnthropicProvider::generateEmbeddings()` throws by design
        | and `AiManager::embeddingsProvider()` only accepts
        | openai/gemini/regolo). Mask strategy (not Tokenise) here
        | because embeddings are one-way — no detokenisation round-trip
        | needed, and mask is stable so the cache hit-rate is
        | preserved across re-ingestion.
        */
        'redact_before_embeddings' => (bool) env('KB_EMBEDDINGS_PII_REDACT', false),

        /*
        | AI insights snippets — when true, `AiInsightsService::coverageGaps()`
        | applies `RedactorEngine::redact($snippet, MaskStrategy)` to every
        | chat sample question BEFORE clustering. Short-circuits leakage to
        | BOTH the LLM call and the snapshot persisted into
        | `admin_insights_snapshots.payload_json`. Insights snapshots are
        | read by the admin UI, so any PII leaking into the snapshot
        | surface would surface in the dashboard.
        */
        'redact_insights_snippets' => (bool) env('KB_INSIGHTS_PII_REDACT', false),

        /*
        | v4.6 — connector ingest boundary. When true, the
        | `App\Connectors\HostIngestionBridge::redactContent()` IoC
        | method (called by every standalone `padosoft/askmydocs-connector-*`
        | package BEFORE it writes the freshly-fetched document body
        | to the KB disk) applies `RedactorEngine::redact($content,
        | MaskStrategy)`. Mask strategy because the redacted form ends
        | up on the KB disk and inside `knowledge_documents.metadata` —
        | one-way semantics like the embedding cache (no round-trip
        | tokenisation required). Default off so existing connector
        | users see no behaviour change until they explicitly opt in.
        */
        'redact_before_ingest' => (bool) env('KB_CONNECTOR_INGEST_PII_REDACT', false),

        /*
        | Detokenize permission — Spatie permission name required for the
        | `LogViewerController::chatDetokenize` action
        | (`POST /api/admin/logs/chat/{id}/detokenize`) that surfaces the
        | original PII alongside the redacted record. Operators with this
        | permission can recover originals; without it the action returns
        | 403. Every 200 / 403 writes a row to the `admin_command_audit`
        | table (model `App\Models\AdminCommandAudit`) tagged with
        | `command = 'pii.detokenize'`, so unmask attempts are forensically
        | traceable. The 422 strategy-mismatch preflight (when the active
        | redaction strategy is not `tokenise`) is intentionally not
        | audited — it's a config-stage error, not an operator action.
        */
        'detokenize_permission' => (string) env('KB_PII_DETOKENIZE_PERMISSION', 'pii.detokenize'),

        /*
        | v4.3/W1 sub-PR 4.5 — comprehensive boundary coverage knobs.
        | Each one is INDEPENDENTLY default-off and gated by the master
        | `enabled` flag above. See `App\Providers\PiiBoundaryCoverageServiceProvider`
        | for the touch-point wiring.
        */

        /*
        | Monolog log channel processor — when true, the
        | `App\Pii\Logging\PiiRedactingProcessor` is pushed onto every
        | configured Monolog handler so PII is stripped BEFORE the line
        | hits disk. Defence-in-depth: even if a developer logs
        | `Log::info("user " . $request->input('email'))`, the disk
        | record is masked. Mask strategy (not Tokenise) — logs are a
        | forensic trail, not a round-trippable record.
        */
        'redact_logs' => (bool) env('KB_PII_REDACT_LOGS', false),

        /*
        | Failed-jobs payload sanitiser — when true, the
        | `App\Pii\Listeners\RedactFailedJobPayload` listener subscribes
        | to `Illuminate\Queue\Events\JobFailed` and re-writes the
        | `failed_jobs.payload` JSON column through RedactorEngine.
        | Failing jobs serialise their constructor args into payload;
        | a job that took an email or IBAN as a constructor arg would
        | otherwise leak that PII into `failed_jobs` indefinitely.
        */
        'redact_failed_jobs' => (bool) env('KB_PII_REDACT_FAILED_JOBS', false),

        /*
        | Chat answers + sources redaction — when true, the
        | `App\Pii\Observers\ChatLogObserver` redacts `chat_logs.answer`
        | (LLM output may echo PII from ingested corpus) AND walks the
        | `chat_logs.sources` JSON (citation snippets) on `creating`
        | events. Complements v4.1's `persist_chat_redacted` knob which
        | covered the inbound user side (`chat_logs.question` +
        | `messages.content` via the RedactChatPii middleware) — this
        | knob covers the outbound LLM-output side that lands AFTER the
        | middleware path.
        */
        'redact_answers' => (bool) env('KB_PII_REDACT_ANSWERS', false),

        /*
        | Admin command audit redaction — when true, the
        | `App\Pii\Observers\AdminCommandAuditObserver` redacts
        | `stdout_head`, `error_message`, and JSON values inside
        | `args_json` on the `creating` event. Operators occasionally
        | pass paths / emails / IDs as command arguments; this keeps the
        | forensic trail clean of PII without dropping the operator
        | identity columns (user_id stays plain — needed for the audit
        | trail to be useful).
        */
        'redact_command_audit' => (bool) env('KB_PII_REDACT_COMMAND_AUDIT', false),

        /*
        | Flow payload redactor — when true, the
        | `App\Pii\AskMyDocsFlowPayloadRedactor` (implements
        | `Padosoft\LaravelFlow\Contracts\CurrentPayloadRedactorProvider`)
        | is bound into the container so EVERY persisted Flow payload
        | (run input, step results, audit trail rows, webhook outbox
        | bodies) is auto-redacted by the package's
        | `RedactorAwareFlowStore` decorator. ONE wire, comprehensive
        | coverage across the entire saga engine.
        */
        'redact_flow_payloads' => (bool) env('KB_PII_REDACT_FLOW_PAYLOADS', false),
    ],
];
