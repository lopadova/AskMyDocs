<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // An interrupted concurrent build can leave an invalid index behind.
        $invalid = DB::selectOne("SELECT 1 FROM pg_index WHERE indexrelid = to_regclass('idx_kb_documents_title_prefix') AND NOT indisvalid");
        if ($invalid !== null) {
            $this->down();
        }

        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_kb_documents_title_prefix ON knowledge_documents (lower(title) text_pattern_ops)');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_kb_documents_title_prefix');
        }
    }
};
