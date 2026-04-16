<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * GIN index on to_tsvector(chunk_text) for PostgreSQL full-text search.
 *
 * Required when KB_HYBRID_SEARCH_ENABLED=true — without it each FTS query
 * is an O(n) sequential scan on knowledge_chunks.chunk_text.
 *
 * No-op on non-PostgreSQL connections (SQLite tests, MySQL, etc.).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $lang = config('kb.hybrid_search.fts_language', 'italian');

        // Language name is a regconfig identifier — whitelist to prevent SQL injection.
        $allowed = [
            'simple', 'italian', 'english', 'german', 'french',
            'spanish', 'portuguese', 'dutch', 'danish', 'finnish',
            'hungarian', 'norwegian', 'romanian', 'russian', 'swedish', 'turkish',
        ];

        if (! in_array($lang, $allowed, true)) {
            $lang = 'simple';
        }

        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_chunks_fts ON knowledge_chunks USING GIN (to_tsvector('{$lang}', chunk_text))"
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS idx_chunks_fts');
    }
};
