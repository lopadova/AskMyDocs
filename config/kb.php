<?php

return [
    'embeddings_dimensions' => env('KB_EMBEDDINGS_DIMENSIONS', 1536),
    'default_min_similarity' => env('KB_MIN_SIMILARITY', 0.30),
    'default_limit' => env('KB_DEFAULT_LIMIT', 8),

    /*
    |--------------------------------------------------------------------------
    | Per-project isolation (within a tenant)
    |--------------------------------------------------------------------------
    |
    | OFF (default): the historical model — any user holding `kb.read.any`
    | (viewer/editor/admin/super-admin by seed) reads EVERY project in their
    | tenant. `project_memberships` are dormant. No behaviour change for
    | existing deployments.
    |
    | ON: the "see all projects" capability switches from the blanket
    | `kb.read.any` to the dedicated `kb.read.all_projects` permission
    | (granted to admin + super-admin only). Every other user is constrained
    | to the project_key set of their `project_memberships` rows — so an
    | operator grants a user EITHER all-projects (via the permission/role)
    | OR a specific set of N projects (via memberships). Enforced uniformly
    | by AccessScopeScope across chat, search, autocomplete and the admin KB
    | surface; cross-project content can no longer appear in answers or
    | citations. Tenant isolation is unconditional and unaffected by this
    | flag. (MCP tokens are tenant-scoped service credentials with no user
    | identity, so they remain tenant-wide regardless of this flag.)
    |
    */
    'project_isolation' => [
        'enabled' => env('KB_PROJECT_ISOLATION_ENABLED', false),
    ],

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
        // Fixed FTS dictionary — also the FALLBACK when per-query detection is
        // off or can't confidently determine the query language (v8.8/W5).
        'fts_language' => env('KB_FTS_LANGUAGE', 'italian'),
        // v8.8/W5 — detect each query's language and stem with the matching
        // PostgreSQL dictionary instead of the fixed one above. Default OFF
        // (opt-in): when off, behaviour is byte-for-byte the previous fixed
        // dictionary. `fts_supported_languages` bounds the candidate set.
        'fts_language_detection' => (bool) env('KB_FTS_LANGUAGE_DETECTION', false),
        // Lowercased + trimmed: PostgreSQL `regconfig` names are lowercase, so
        // `English, Italian` must normalize or detection would silently no-op.
        'fts_supported_languages' => array_values(array_filter(array_map(
            static fn (string $l): string => strtolower(trim($l)),
            explode(',', (string) env('KB_FTS_SUPPORTED_LANGUAGES', 'english,italian')),
        ))),
        'rrf_k' => env('KB_RRF_K', 60),
        'semantic_weight' => env('KB_HYBRID_SEMANTIC_WEIGHT', 0.70),
        'fts_weight' => env('KB_HYBRID_FTS_WEIGHT', 0.30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Synonym Expansion (v8.7/W1)
    |--------------------------------------------------------------------------
    |
    | Industry-specific synonym groups registered per (tenant, project) in
    | `kb_synonyms`. At retrieval time SynonymExpander bidirectionally
    | expands a query: mentioning any group member also searches every
    | other member, so in-house jargon / acronyms / product codenames
    | connect to their plain-language equivalents even when the embedding
    | model has never seen the in-house term. The expanded text enriches
    | the query embedding (all drivers) and OR-expands the FTS tsquery
    | (pgsql). No-op when disabled or no synonym groups exist for the
    | active (tenant, project) — zero behaviour change for hosts that
    | never register a synonym.
    |
    */

    'synonyms' => [
        'enabled' => (bool) env('KB_SYNONYM_EXPANSION_ENABLED', true),
        // Per-(tenant, project) synonym map cache TTL. 0 disables caching
        // (every query reloads from the DB) — useful in tests.
        'cache_ttl_seconds' => (int) env('KB_SYNONYM_CACHE_TTL_SECONDS', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | AI deep-analysis on document change (v8.7/W3–W4)
    |--------------------------------------------------------------------------
    |
    | When a document is ingested or modified, an async
    | `AnalyzeDocumentChangeJob` asks the LLM to (a) suggest how to
    | strengthen the doc, (b) surface its cross-references with existing
    | docs, and (c) flag which OTHER docs this change makes obsolete /
    | in need of revision. Results land in `kb_doc_analyses` and notify
    | the doc's reviewers (`KbDocAnalysisReady`). Suggest-only — never
    | mutates a doc (ADR 0003).
    |
    | Cost posture: ON for canonical docs by default (highest-value,
    | lower-volume), OFF (opt-in) for non-canonical. `enabled` is the
    | master kill-switch. `debounce_minutes` skips re-analysing a doc that
    | was analysed within the window (rapid re-ingest guard).
    |
    */

    'change_analysis' => [
        'enabled' => (bool) env('KB_CHANGE_ANALYSIS_ENABLED', true),
        'canonical_default' => (bool) env('KB_CHANGE_ANALYSIS_CANONICAL', true),
        'non_canonical_default' => (bool) env('KB_CHANGE_ANALYSIS_NON_CANONICAL', false),
        // v8.8/W2 — also run the obsolescence-impact analysis on a
        // user-initiated DELETE (on top of the global `enabled` switch).
        'delete_enabled' => (bool) env('KB_CHANGE_ANALYSIS_ON_DELETE', true),
        'neighbor_limit' => (int) env('KB_CHANGE_ANALYSIS_NEIGHBORS', 5),
        'debounce_minutes' => (int) env('KB_CHANGE_ANALYSIS_DEBOUNCE_MINUTES', 60),
        'queue' => env('KB_CHANGE_ANALYSIS_QUEUE', 'default'),
        // v8.11/P4 (RESERVED — declared now as the cycle's config surface; the
        // SuggestionApplier that reads this lands in a later v8.11.x release).
        // When ON (global; per-(tenant,project) override via kb_analysis_settings),
        // eligible change/delete suggestions will be AUTO-APPLIED (auto-add
        // cross-refs, auto-deprecate dangling refs) instead of only suggested,
        // audited + reversible (Time Machine). Default OFF — suggest-only stays
        // the default; manual "Apply" from the UI is always available (R43).
        'autoapply_enabled' => (bool) env('KB_CHANGE_AUTOAPPLY_ENABLED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Auto-Wiki — Karpathy LLM-Wiki auto-compilation (v8.11)
    |--------------------------------------------------------------------------
    |
    | When enabled, after a document is ingested (md → chunked → embedded) the
    | AutoWikiCompiler asks the LLM to auto-enrich the doc's frontmatter (tags,
    | summary, aliases, cross-references) and synthesize concept pages, writing
    | them as a SECOND-CLASS `auto` tier (generation_source='auto'). The auto
    | tier is real, searchable and graph-navigable, but the reranker firewall
    | (kb.canonical.auto_tier_penalty) ranks human-`accepted` above it, and an
    | admin can promote auto → human. Suggest-only never applies here — the
    | auto tier is its own reversible, audited layer that EXTENDS ADR 0003
    | (the human-vouched tier keeps its human gate). Layered per-(tenant,
    | project) override via kb_analysis_settings; R43 both-states.
    |
    | The LLM calls MAY target a model distinct from the main chat provider
    | (KB_AUTOWIKI_AI_PROVIDER/_MODEL) — empty falls back to the default chat
    | provider/model, so no behaviour change unless set.
    |
    */

    'autowiki' => [
        'enabled' => (bool) env('KB_AUTOWIKI_ENABLED', true),
        'canonical_default' => (bool) env('KB_AUTOWIKI_CANONICAL', true),
        'non_canonical_default' => (bool) env('KB_AUTOWIKI_NON_CANONICAL', true),
        'debounce_minutes' => (int) env('KB_AUTOWIKI_DEBOUNCE_MINUTES', 60),
        'neighbor_limit' => (int) env('KB_AUTOWIKI_NEIGHBORS', 5),
        'queue' => env('KB_AUTOWIKI_QUEUE', 'default'),

        // P2 — graph canonicalization. When ON (default), after a doc is
        // enriched its allow-listed cross-references are materialised into real
        // kb_edges (provenance='inferred') + the kb_nodes they connect, so the
        // auto tier becomes navigable. An auto doc with no slug is given a
        // stable per-project one so the WHOLE corpus participates (enterprise
        // scope). OFF => enrichment still runs but no graph is built (R43).
        'graph_enabled' => (bool) env('KB_AUTOWIKI_GRAPH_ENABLED', true),

        // P3 — concept-page synthesis (project sweep, explicit trigger only —
        // command/API/MCP/scheduler, never per-ingest). When ON (default), a
        // sweep synthesizes an auto-tier `domain-concept` page for each
        // recurring concept (a tag appearing in >= min_frequency docs) that has
        // no page yet, capped at max_per_run per invocation. OFF => clean no-op
        // (R43).
        'concepts_enabled' => (bool) env('KB_AUTOWIKI_CONCEPTS_ENABLED', true),
        'concepts_min_frequency' => (int) env('KB_AUTOWIKI_CONCEPTS_MIN_FREQUENCY', 3),
        'concepts_max_per_run' => (int) env('KB_AUTOWIKI_CONCEPTS_MAX_PER_RUN', 5),

        // P7 — cross-model review / novelty gate. An independent review-LLM
        // validates an auto page (grounding / cross-refs / novelty /
        // contradictions) before it's trusted. Explicit trigger only (never
        // per-ingest). Point the review model at a DIFFERENT provider/model than
        // the compiler for true cross-model diversity (empty => default chat).
        'review_enabled' => (bool) env('KB_AUTOWIKI_REVIEW_ENABLED', true),
        'review_ai_provider' => env('KB_AUTOWIKI_REVIEW_AI_PROVIDER') ?: null,
        'review_ai_model' => env('KB_AUTOWIKI_REVIEW_AI_MODEL') ?: null,

        // P9 — scheduled wiki maintenance: max un-enriched docs to backfill
        // (dispatch the compiler for) per project per maintenance run.
        'maintenance_backfill_limit' => (int) env('KB_AUTOWIKI_MAINTENANCE_BACKFILL_LIMIT', 25),

        // Dedicated AI model override for the auto-compile LLM calls. Empty =>
        // fall back to the default chat provider/model (config('ai.default')).
        // NB: `?: null` normalizes a present-but-blank env var ("" from
        // `KB_AUTOWIKI_AI_PROVIDER=` in .env) to null — env() returns "" not
        // null in that case, which would otherwise resolve an EMPTY provider.
        'ai_provider' => env('KB_AUTOWIKI_AI_PROVIDER') ?: null,
        'ai_model' => env('KB_AUTOWIKI_AI_MODEL') ?: null,

        // P3 — agentic wiki-navigation retrieval (multi-hop + index-anchored).
        // Default ON but only flipped after the benchmark proves the leap; its
        // own dedicated model override (empty => default chat).
        'retrieval_enabled' => (bool) env('KB_AUTOWIKI_RETRIEVAL_ENABLED', true),
        'agentic_ai_provider' => env('KB_AGENTIC_AI_PROVIDER') ?: null,
        'agentic_ai_model' => env('KB_AGENTIC_AI_MODEL') ?: null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Engagement digest (v8.15/W2)
    |--------------------------------------------------------------------------
    | The rich weekly/monthly KB digest sent to email + Discord/Slack/Teams.
    | The AI narrative ("what changed in your KB and why it matters") is
    | default-ON but config-gated (R43 — tested OFF and ON) and uses a
    | DEDICATED model so digests never compete for the primary chat model.
    | Default = a free OpenRouter model (digests are summary prose, not
    | latency/quality-critical → ~$0 cost). Confirm the exact `:free` id at
    | deploy time — OpenRouter's free roster shifts. When the narrative LLM
    | is disabled or unreachable, the digest degrades to deterministic copy
    | (R14) — it never fails the send.
    */
    'digest' => [
        'ai_narrative_enabled' => (bool) env('KB_DIGEST_AI_NARRATIVE_ENABLED', true),
        'ai_provider' => env('KB_DIGEST_AI_PROVIDER', 'openrouter') ?: null,
        'ai_model' => env('KB_DIGEST_AI_MODEL', 'meta-llama/llama-3.3-70b-instruct:free') ?: null,
        'narrative_max_tokens' => (int) env('KB_DIGEST_NARRATIVE_MAX_TOKENS', 400),
        // In-app digest feed retention; `digest:prune-feed` drops entries older
        // than this (0 disables the rotation).
        'feed_retention_days' => (int) env('KB_DIGEST_FEED_RETENTION_DAYS', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Gamification (v8.15/W5) — OPT-IN
    |--------------------------------------------------------------------------
    | A tasteful contributor-motivation layer: badges awarded when a user's
    | all-time engagement crosses a threshold, surfaced on the "My KB"
    | dashboard alongside the (already-shipped) leaderboard. DEFAULT-OFF
    | (R43 — tested both states); when off, no badges are awarded or shown.
    | The badge catalog is config-driven so an operator can tune labels /
    | thresholds (and per-tenant overrides can layer on top later) — each
    | badge declares a `metric` (score | events | authored | active_days,
    | all-time) and the `threshold` to reach it.
    */
    'gamification' => [
        'enabled' => (bool) env('KB_GAMIFICATION_ENABLED', false),
        'badges' => [
            ['key' => 'first_contribution', 'label' => 'First contribution', 'icon' => '🌱', 'metric' => 'events', 'threshold' => 1],
            ['key' => 'contributor', 'label' => 'Contributor', 'icon' => '✍️', 'metric' => 'score', 'threshold' => 25],
            ['key' => 'prolific', 'label' => 'Prolific contributor', 'icon' => '🚀', 'metric' => 'score', 'threshold' => 100],
            ['key' => 'author', 'label' => 'Author', 'icon' => '📚', 'metric' => 'authored', 'threshold' => 5],
            ['key' => 'regular', 'label' => 'Regular', 'icon' => '🔥', 'metric' => 'active_days', 'threshold' => 5],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Source retention policy (v8.11) — SCHEMA/CONFIG FOUNDATION
    |--------------------------------------------------------------------------
    |
    | NOTE: this knob + the `knowledge_documents.markdown_path` column are the
    | foundation declared in v8.11.0; the INGEST WIRING that reads this mode and
    | writes the markdown artifact / drops the original lands with the
    | AutoWikiCompiler in a later v8.11.x release. Until then ingest behaves as
    | before (`reference_only`-style metadata + chunks, original kept on disk).
    |
    | Intended (once wired) — what is kept on ingest, globally (and per-connector
    | via config/connectors.php overrides):
    |   - full_copy      : original binary on the KB disk + chunks + the
    |                      converted markdown as a first-class artifact
    |                      (knowledge_documents.markdown_path). Today's default
    |                      behaviour, plus the faithful markdown so it no longer
    |                      has to be re-derived lossily from chunks.
    |   - markdown_only  : converted markdown artifact + chunks; the original
    |                      binary is dropped after conversion (smaller footprint).
    |   - reference_only : only metadata + external_url/external_id + chunks/
    |                      embeddings; the original is NOT copied — open on
    |                      demand via the connector link/API (for Asana/Notion/
    |                      Drive etc.).
    |
    */

    'source_retention' => [
        // `?: 'full_copy'` (not env(..., 'full_copy')): a present-but-blank
        // KB_SOURCE_RETENTION= returns "" from env(), which is an INVALID mode —
        // the default only kicks in when the var is ABSENT. Same normalization
        // as the auto-wiki AI override knobs above.
        'mode' => env('KB_SOURCE_RETENTION') ?: 'full_copy',
    ],

    /*
    |--------------------------------------------------------------------------
    | Content-gap analytics (v8.8/W4)
    |--------------------------------------------------------------------------
    |
    | Every refused chat turn (deterministic grounding gate OR LLM
    | self-refusal sentinel) increments a `kb_search_failures` rollup so the
    | admin "Content Gaps" panel can rank the questions the KB could not
    | answer. Recording is a side-channel that never breaks the chat path.
    */

    'content_gaps' => [
        'enabled' => (bool) env('KB_CONTENT_GAPS_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Anonymous chat (authenticated, NOT persisted)
    |--------------------------------------------------------------------------
    |
    | When enabled, `POST /api/kb/chat` accepts `anonymous: true` for an
    | authenticated user to run a one-off KB chat that is NEVER saved as a
    | conversation/message and is logged only minimally (see
    | `chat-log.anonymous_level`). It does NOT bypass any guard: tenant scope,
    | RBAC, AI-Act disclosure/consent, grounding/refusal, and PII redaction all
    | still apply. The question is redacted with a NON-PERSISTENT strategy
    | (`mask` — no reversible token map is written) so no PII is stored anywhere.
    | Default OFF; when off, an `anonymous: true` request is rejected (422) so it
    | can never silently fall back to a persisted turn (R43).
    |
    */

    'anonymous_chat' => [
        'enabled' => (bool) env('KB_ANONYMOUS_CHAT_ENABLED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cloud Time Machine — archived-version retention (v8.7/W5)
    |--------------------------------------------------------------------------
    |
    | Every re-ingest archives the prior document version + its chunks so the
    | Time Machine can browse/diff/restore them. `keep_archived` caps how many
    | ARCHIVED versions to retain per (tenant, project, source_path) family;
    | `kb:prune-archived-versions` (daily) hard-deletes the rest. The live
    | version and soft-deleted rows are never pruned.
    |
    */

    'versioning' => [
        'keep_archived' => (int) env('KB_KEEP_ARCHIVED_VERSIONS', 10),
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

        // v8.1 — additive boost applied to chunks whose document was
        // @mentioned by the user, when `kb.mentions.mode = boost` (the
        // default). Large enough to float a mentioned doc to the top of the
        // candidate set without hard-excluding other relevant results.
        'mention_boost_weight'  => (float) env('KB_RERANK_MENTION_BOOST_WEIGHT', 0.50),

        // v8.2 (finding #7/#9) — min-max normalise the candidate vector
        // signal to 0..1 before fusion, so semantic (cosine 0..1) and hybrid
        // (RRF ~0.01) scores are comparable and lexically-strong hybrid hits
        // aren't drowned. The raw vector_score (refusal floor + citation
        // evidence) is untouched. v8.2 — flipped ON by default after the LIVE
        // kb:benchmark validated it (hybrid on + priority_weight 0.001:
        // nDCG@5 0.969 → 0.997, MRR 0.958 → 1.000, no regressions). Set
        // false to revert to raw-score fusion.
        'normalize_candidate_scores' => (bool) env('KB_RERANK_NORMALIZE_SCORES', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | @mention handling (v8.1)
    |--------------------------------------------------------------------------
    |
    | When a user @mentions documents in the composer, their ids arrive as
    | `filters.doc_ids`. Two modes:
    |   - `boost`  (default): mentioned docs are NOT a hard filter — every
    |     relevant chunk is still retrieved, and the reranker floats the
    |     mentioned docs to the top via `reranking.mention_boost_weight`.
    |     Preserves recall (a mention is a hint, not an allowlist).
    |   - `filter`: legacy behaviour — restrict retrieval to the mentioned
    |     docs only (hard `WHERE id IN (...)`).
    |
    */

    'mentions' => [
        'mode' => env('KB_MENTIONS_MODE', 'boost'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Result diversification (v8.1)
    |--------------------------------------------------------------------------
    |
    | `max_chunks_per_doc` caps how many chunks from a SINGLE document may
    | occupy the reranked top-k, so one verbose document can't crowd out
    | every other source (the "6 chunks from the same doc" problem). The cap
    | is applied AFTER reranking, by descending score, BEFORE the top-k cut —
    | so a doc keeps its strongest chunks and the freed slots go to the next
    | best chunks from OTHER docs. 0 disables the cap. Default 3 guarantees
    | at least ceil(limit / 3) distinct documents in an 8-chunk context.
    |
    */

    'diversification' => [
        'max_chunks_per_doc' => (int) env('KB_DIVERSIFICATION_MAX_CHUNKS_PER_DOC', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Vector-search internals (v8.2)
    |--------------------------------------------------------------------------
    |
    | `php_cosine_prefetch_cap` bounds how many candidate chunks the non-pgsql
    | (SQLite test/benchmark) PHP-cosine fallback pre-fetches before scoring,
    | keeping memory sane. Production (pgsql + native pgvector) ignores it.
    |
    */

    'search' => [
        'php_cosine_prefetch_cap' => (int) env('KB_SEARCH_PHP_COSINE_PREFETCH_CAP', 2000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retrieval-quality benchmark thresholds (v8.2)
    |--------------------------------------------------------------------------
    |
    | Enterprise pass thresholds for `kb:benchmark --gate`. The aggregate
    | scorecard must meet ALL of these for the gate to pass.
    |
    */

    'benchmark' => [
        'threshold_ndcg' => (float) env('KB_BENCHMARK_THRESHOLD_NDCG', 0.80),
        'threshold_mrr' => (float) env('KB_BENCHMARK_THRESHOLD_MRR', 0.85),
        'threshold_citation_precision' => (float) env('KB_BENCHMARK_THRESHOLD_CITATION_PRECISION', 0.90),
        'threshold_refusal_accuracy' => (float) env('KB_BENCHMARK_THRESHOLD_REFUSAL_ACCURACY', 0.95),
    ],

    /*
    |--------------------------------------------------------------------------
    | Chunking
    |--------------------------------------------------------------------------
    |
    | `hard_cap_tokens` bounds each emitted chunk (approx tokens, strlen/4).
    | `overlap_tokens` (wired into MarkdownChunker since v8.18) duplicates the
    | tail of each oversized-section piece onto the head of the next piece,
    | snapped to paragraph boundaries (never mid-word), so an answer straddling
    | a chunk boundary still appears whole in at least one chunk. 0 = OFF (chunks
    | have zero overlap — the pre-v8.18 behaviour). `target_tokens` is the soft
    | target used by the connector chunkers (Notion/Jira/Confluence/...).
    |
    | RE-INGEST REQUIRED: changing `overlap_tokens` changes chunk text, hence
    | `knowledge_chunks.chunk_hash`, hence is NOT idempotent against already-
    | ingested docs — it forces a new document version + re-embed of the changed
    | chunks (the embedding cache is keyed on text_hash, so changed text misses
    | the cache). Treat it like the embedding-dimensions contract: flip it, then
    | re-ingest the corpus. PdfPageChunker keeps page-granular chunks and
    | intentionally does NOT overlap.
    |
    */
    'chunking' => [
        'target_tokens' => env('KB_CHUNK_TARGET_TOKENS', 512),
        'hard_cap_tokens' => env('KB_CHUNK_HARD_CAP_TOKENS', 1024),
        // Tail-overlap budget for MarkdownChunker; 0 = off. Re-ingest required when changed.
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
        // v8.2 — calibrated 0.003 → 0.001 against the live retrieval
        // benchmark: at 0.003 the canonical priority boost (max ~0.27 for
        // priority 90) drowned semantic relevance, floating canonical docs
        // above genuinely more-relevant non-canonical ones (e.g. a DB query
        // returned cache docs instead of the DB runbook). 0.001 keeps a
        // canonical tie-breaker nudge (max ~0.09) while relevance wins.
        // Benchmark: MRR 0.833 → 0.958, nDCG@5 0.855 → 0.969.
        'priority_weight' => (float) env('KB_CANONICAL_PRIORITY_WEIGHT', 0.001),
        'superseded_penalty' => (float) env('KB_CANONICAL_SUPERSEDED_PENALTY', 0.40),
        'deprecated_penalty' => (float) env('KB_CANONICAL_DEPRECATED_PENALTY', 0.40),
        'archived_penalty' => (float) env('KB_CANONICAL_ARCHIVED_PENALTY', 0.60),
        // v8.11 Auto-Wiki firewall: a small rerank penalty applied to AUTO-tier
        // documents (generation_source='auto') so a human-curated `accepted`
        // doc on the same topic always outranks the auto-compiled one
        // (anti-hallucination guarantee). Small by design — auto docs still
        // rank above raw/non-canonical; the penalty only breaks ties against
        // the human-vouched tier. 0 disables the firewall (auto == human rank).
        // Default 0.02 < the canonical priority boost at default priority 50
        // (0.001×50 = 0.05), so a default-priority AUTO doc still outranks raw
        // (0.05−0.02 = 0.03 > 0) while a human `accepted` doc on the same topic
        // outranks it by exactly this penalty.
        'auto_tier_penalty' => (float) env('KB_CANONICAL_AUTO_TIER_PENALTY', 0.02),
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
        // P6 — default BFS depth for the multi-hop WikiNavigator (distinct from
        // the legacy 1-hop GraphExpander above). Bounded 1..5 at the call site.
        'expansion_depth' => (int) env('KB_GRAPH_EXPANSION_DEPTH', 2),
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
        // v8.2 — calibrated 0.45 → 0.40 against the live benchmark so a
        // strategy query surfaces its related rejected-approach context
        // (rejected-recall 0.5 → 1.0) without over-injecting (refusal +
        // citation precision stayed 1.0).
        'min_similarity' => (float) env('KB_REJECTED_MIN_SIMILARITY', 0.40),
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
    | v8.1 — the gate now considers the FINAL ranking signal too. A chunk
    | grounds an answer when its `rerank_score` clears `min_rerank_score`
    | OR its raw `vector_score` clears `min_chunk_similarity`. The rerank
    | floor admits lexically/heading-strong matches the reranker promotes
    | even when their raw vector similarity is modest — they used to be
    | wrongly refused. The default 0.25 is calibrated to a vector-only chunk
    | sitting AT the 0.45 similarity floor (vector_weight 0.55 × 0.45 ≈
    | 0.247): a pure-vector chunk BELOW the floor still refuses, but a chunk
    | whose rerank score is lifted above 0.25 by keyword/heading/canonical
    | signal grounds even on a modest vector score. Lower it to refuse less,
    | raise it to refuse more. The OR keeps the well-understood semantic
    | floor as a safety net. See {@see App\Services\Kb\Retrieval\RetrievalGrounding}.
    |
    */

    'refusal' => [
        'min_chunk_similarity' => (float) env('KB_REFUSAL_MIN_SIMILARITY', 0.45),
        'min_rerank_score' => (float) env('KB_REFUSAL_MIN_RERANK_SCORE', 0.25),
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
