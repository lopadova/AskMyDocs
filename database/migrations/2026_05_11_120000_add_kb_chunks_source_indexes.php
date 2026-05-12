<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v4.5/W5.5 — GIN indexes on `knowledge_chunks.metadata` for the
 * SPA `facets[source]` + `facets[tag]` query patterns.
 *
 * PostgreSQL only: SQLite doesn't have GIN, doesn't have a JSONB
 * path operator, and our test suite runs every query against
 * SQLite under tests/database/migrations/. The driver guard keeps
 * tests green AND lets the production pgsql query plan benefit
 * from the index when the facets fire.
 *
 * What gets indexed (all three expressions cast to `jsonb` because
 * `knowledge_chunks.metadata` is the legacy `json` column type —
 * see the inline comment in `up()` for the rationale):
 *
 *   - `((metadata::jsonb)->'source_type')` — GIN-jsonb-ops index.
 *     The v4.5/W5.5 per-chunk source tag set by the source-aware
 *     chunkers. Matches the SPA "filter by Notion / Confluence /
 *     Drive / …" facet at chunk-grain, not document-grain. Use
 *     `metadata::jsonb @> '{"source_type":"notion"}'::jsonb` on
 *     the read path so the planner picks this index.
 *
 *   - `((metadata::jsonb)->'search_tags')` — GIN-jsonb-ops (the
 *     default opclass), NOT `jsonb_path_ops`. Supports BOTH the
 *     `@>` containment operator AND the `?|` "any of these keys"
 *     operator, so a tag-overlap filter like
 *     `metadata::jsonb->'search_tags' ?| ARRAY['decision','cache']`
 *     uses the index. Tag overlap is the highest-value of the
 *     four Layer-4 reranker signals and the most likely SPA
 *     facet to fire on a busy tenant. `jsonb_path_ops` would be
 *     smaller / faster but ONLY supports `@>` — switching to it
 *     would silently break the `?|` query path.
 *
 *   - `((metadata::jsonb)->>'recency_bucket')` — B-tree index on
 *     the text-projection (`->>` returns text). Recency bucket
 *     is a small finite domain (4 values) so the cardinality
 *     warrants a B-tree, not a GIN.
 *
 * Idempotent: `CREATE INDEX IF NOT EXISTS` so the migration
 * survives re-runs against partial schemas (test fixtures, dev
 * resets).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // The `knowledge_chunks.metadata` column is `json`, not `jsonb`
        // — switching the column type would rewrite the entire table
        // and is out of scope for W5.5. Instead, every GIN-on-JSON
        // expression below casts to `jsonb` at index-eval time so the
        // index actually builds (json has no default GIN op class;
        // jsonb does). The query patterns in KbSearchService cast on
        // the read path too so the planner uses the index.
        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_kb_chunks_source_type ".
            "ON knowledge_chunks USING gin (((metadata::jsonb)->'source_type'))"
        );
        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_kb_chunks_search_tags ".
            // Default `jsonb_ops` opclass (i.e. no explicit opclass) so
            // BOTH `@>` and `?|` queries can use this index. The smaller
            // `jsonb_path_ops` variant would only support `@>` — which
            // would silently disable the index for the tag-overlap
            // facet that ships in the SPA.
            "ON knowledge_chunks USING gin (((metadata::jsonb)->'search_tags'))"
        );
        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_kb_chunks_recency_bucket ".
            "ON knowledge_chunks (((metadata::jsonb)->>'recency_bucket'))"
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS idx_kb_chunks_source_type');
        DB::statement('DROP INDEX IF EXISTS idx_kb_chunks_search_tags');
        DB::statement('DROP INDEX IF EXISTS idx_kb_chunks_recency_bucket');
    }
};
