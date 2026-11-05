<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v8.42/W2 (ADR 0035 §D2) — record HOW each chunk's vector was produced.
 *
 * `embedding_fingerprint` = first 16 hex chars of sha256(provider | model |
 * dimensions | input-template id | target/overlap/hard-cap tokens). It is the
 * only way to tell, after a provider/model/template/chunking change, which
 * vectors in the index are no longer comparable with a freshly embedded query.
 * `kb:reembed-stale` selects exactly the chunks whose fingerprint is NULL
 * (written before this column existed) or differs from the active one.
 *
 * Nullable on purpose: existing rows stay valid and are reported as "stale"
 * until re-embedded — nothing is rewritten by this migration (R3: no backfill
 * of a large table inside a migration). `chunks` is tenant-aware (R31) so the
 * index leads with `tenant_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('knowledge_chunks', 'embedding_fingerprint')) {
            Schema::table('knowledge_chunks', function (Blueprint $table): void {
                $table->string('embedding_fingerprint', 16)->nullable()->after('embedding');
                $table->index(['tenant_id', 'embedding_fingerprint'], 'idx_kb_chunks_tenant_emb_fp');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('knowledge_chunks', 'embedding_fingerprint')) {
            Schema::table('knowledge_chunks', function (Blueprint $table): void {
                $table->dropIndex('idx_kb_chunks_tenant_emb_fp');
                $table->dropColumn('embedding_fingerprint');
            });
        }
    }
};
