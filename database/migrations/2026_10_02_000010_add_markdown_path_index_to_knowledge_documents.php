<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v8.36 / ADR 0030 §8 — every artifact removal (hard delete, prune, orphan
 * sweep) re-checks `knowledge_documents.markdown_path` under the artifact
 * path's lock, and the orphan sweep judges batches of paths by the same
 * column: an index keeps that gate a lookup, not a scan of the corpus per
 * removed artifact.
 *
 * `markdown_path` is a string(1024) but its values are bounded (~1.4 KB at
 * most: `.artifacts/` + two safe segments of <= 120 ASCII chars + a
 * source_path of <= 255 chars + `.versions/` + 64 hex + `.md`), well under
 * PostgreSQL's btree entry limit — the supported target. A MySQL/MariaDB
 * port would need a prefix or hash index here (utf8mb4 caps a key at 3072
 * bytes and 1024 x 4 exceeds it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_documents', function (Blueprint $table): void {
            $table->index('markdown_path', 'knowledge_documents_markdown_path_index');
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_documents', function (Blueprint $table): void {
            $table->dropIndex('knowledge_documents_markdown_path_index');
        });
    }
};
