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
 * What gets indexed:
 *
 *   - (metadata->>'source_type')   — the v4.5/W5.5 per-chunk source
 *     tag set by the source-aware chunkers. Matches the SPA
 *     "filter by Notion / Confluence / Drive / …" facet at
 *     chunk-grain, not document-grain.
 *
 *   - (metadata->'search_tags')    — GIN-jsonb-ops index so
 *     `metadata->'search_tags' ?| ARRAY['decision', 'cache']`
 *     uses the index. Tag overlap is the highest-value of the
 *     four Layer-4 reranker signals and the most likely SPA
 *     facet to fire on a busy tenant.
 *
 *   - (metadata->>'recency_bucket')— B-tree index. Recency bucket
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

        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_kb_chunks_source_type ".
            "ON knowledge_chunks USING gin ((metadata->'source_type'))"
        );
        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_kb_chunks_search_tags ".
            "ON knowledge_chunks USING gin ((metadata->'search_tags') jsonb_path_ops)"
        );
        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_kb_chunks_recency_bucket ".
            "ON knowledge_chunks ((metadata->>'recency_bucket'))"
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
