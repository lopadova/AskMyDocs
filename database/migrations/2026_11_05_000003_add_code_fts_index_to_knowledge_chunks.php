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
 * Built CONCURRENTLY: even an empty partial index scans the existing corpus,
 * and a plain CREATE INDEX would block inserts/updates on knowledge_chunks
 * (ingestion, re-embedding) for that whole scan, on every deployment, with
 * code ingestion OFF. Same recovery as the trigram migration
 * (2026_10_22_000002): an interrupted concurrent build leaves an INVALID
 * index that `IF NOT EXISTS` would silently keep, so it is dropped first.
 *
 * No-op on non-PostgreSQL connections (SQLite tests, MySQL), exactly like the
 * prose GIN index migration.
 */
return new class extends Migration
{
    // Concurrent builds cannot run inside a transaction.
    public $withinTransaction = false;

    private const INDEX = 'idx_chunks_fts_code';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $invalid = DB::selectOne('SELECT 1 FROM pg_index WHERE indexrelid = to_regclass(?) AND NOT indisvalid', [self::INDEX]);
        if ($invalid !== null) {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.self::INDEX);
        }

        DB::statement(
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS '.self::INDEX." ON knowledge_chunks USING GIN (to_tsvector('simple', chunk_text)) WHERE (metadata->>'language') IS NOT NULL"
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.self::INDEX);
    }
};
