<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v4.0/W1.C — TEST mirror of the production migration that adds
 * `tenant_id` to all v3 domain tables.
 *
 * This file is the SQLite test override of:
 *   database/migrations/2026_04_28_000001_add_tenant_id_to_v3_tables.php
 *
 * It exists because Orchestra Testbench runs the migrations in
 * `tests/database/migrations/` instead of the production folder so the
 * SQLite-specific schema (e.g. `vector(N)` → JSON cast in chunks) is
 * applied. Keep both files in sync when tenant_id support evolves.
 */
return new class extends Migration {
    private const TABLES = [
        'knowledge_documents',
        'knowledge_chunks',
        // 'embedding_cache' is intentionally EXCLUDED — the cache is a
        // cross-tenant reuse layer. The schema enforces UNIQUE on
        // `text_hash` alone (see
        // 0001_01_01_000006_create_embedding_cache_table.php).
        // EmbeddingCacheService additionally filters by provider + model
        // for retrieval (informational columns), so identical text under
        // a different provider/model produces a cache miss; the supported
        // way to evict stale entries when the embedding model changes is
        // EmbeddingCacheService::flush($provider). tenant_id here would
        // create confusing "first-tenant-wins" ownership semantics that
        // contradict the cross-tenant reuse contract. See PR #98 / PR #99
        // Copilot review (2026-05-02).
        'chat_logs',
        'conversations',
        'messages',
        'kb_nodes',
        'kb_edges',
        'kb_canonical_audit',
        'project_memberships',
        'kb_tags',
        'knowledge_document_tags',
        'knowledge_document_acl',
        'admin_command_audit',
        'admin_command_nonces',
        'admin_insights_snapshots',
        'chat_filter_presets',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            if (Schema::hasColumn($table, 'tenant_id')) {
                continue;
            }
            Schema::table($table, function (Blueprint $t): void {
                $t->string('tenant_id', 50)->default('default');
            });
        }

        // SQLite does not support DROP UNIQUE without rebuilding the
        // table. For tests we don't need the tenant-scoped composite
        // uniques to be enforced at the DB layer — Eloquent + the
        // application layer guard tenant boundaries through R30/R31.
        // We do still want the columns to exist with default 'default'
        // so factories + seeds keep producing valid rows.
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'tenant_id')) {
                continue;
            }
            Schema::table($table, function (Blueprint $t): void {
                $t->dropColumn('tenant_id');
            });
        }
    }
};
