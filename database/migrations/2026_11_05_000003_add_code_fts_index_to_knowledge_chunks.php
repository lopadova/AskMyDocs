<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * v8.45/W5 (ADR 0035 D7) — full-text index for SOURCE CODE chunks.
 *
 * A language stemmer damages identifiers (`calculateVatRounding`,
 * `round_half_up`), so code chunks are searched with the `simple`
 * configuration through a PARTIAL expression index that only covers chunks that
 * carry `metadata.language` (set exclusively by CodeAstChunker). Prose rows are
 * not indexed twice and the index stays empty — zero cost — until code
 * ingestion is used. The predicate here must stay textually identical to the
 * one KbSearchService::fullTextSearch() adds, or the planner will not
 * choose the index.
 *
 * `IF NOT EXISTS` keeps it re-runnable; no-op on non-PostgreSQL connections
 * (SQLite tests, MySQL), exactly like the prose GIN index migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_chunks_fts_code ON knowledge_chunks USING GIN (to_tsvector('simple', chunk_text)) WHERE (metadata->>'language') IS NOT NULL"
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS idx_chunks_fts_code');
    }
};
