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
    | Weights must sum to 1.0. Adjust to tune precision vs. recall trade-off.
    |
    */

    'reranking' => [
        'enabled' => env('KB_RERANKING_ENABLED', true),
        'candidate_multiplier' => env('KB_RERANK_CANDIDATE_MULTIPLIER', 3),
        'vector_weight' => env('KB_RERANK_VECTOR_WEIGHT', 0.60),
        'keyword_weight' => env('KB_RERANK_KEYWORD_WEIGHT', 0.30),
        'heading_weight' => env('KB_RERANK_HEADING_WEIGHT', 0.10),
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
];
