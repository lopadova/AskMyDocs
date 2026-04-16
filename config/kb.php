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

    'sources' => [
        'markdown_root' => env('KB_MARKDOWN_ROOT', base_path('../project/docs')),
    ],
];
