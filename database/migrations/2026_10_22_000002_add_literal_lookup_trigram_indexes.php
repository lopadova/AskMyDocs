<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Concurrent builds keep ingestion available and cannot run in a transaction.
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        // FTS indexes do not support the literal lookup's LOWER(...) LIKE '%...%'.
        foreach ([
            'idx_kb_chunks_literal_trgm' => 'ON knowledge_chunks USING GIN (LOWER(chunk_text) gin_trgm_ops)',
            'idx_kb_documents_title_trgm' => 'ON knowledge_documents USING GIN (LOWER(title) gin_trgm_ops)',
        ] as $name => $definition) {
            // An interrupted concurrent build can leave an unusable named index
            // that IF NOT EXISTS would otherwise silently keep on a deploy retry.
            $invalid = DB::selectOne('SELECT 1 FROM pg_index WHERE indexrelid = to_regclass(?) AND NOT indisvalid', [$name]);
            if ($invalid !== null) {
                DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.$name);
            }
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS '.$name.' '.$definition);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_kb_documents_title_trgm');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_kb_chunks_literal_trgm');
        // pg_trgm may also serve indexes owned by other features.
    }
};
