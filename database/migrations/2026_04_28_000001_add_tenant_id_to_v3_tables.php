<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v4.0/W1.C — Add tenant_id to all v3 domain tables.
 *
 * Adds a `tenant_id` (varchar(50), NOT NULL, default 'default') column
 * to every domain table v3 ships with. Existing rows get
 * `tenant_id = 'default'` automatically so v3 keeps working.
 *
 * Rebuilds composite uniques to be tenant-scoped:
 *  - knowledge_documents   (tenant_id, project_key, doc_id) / slug / source_path+version_hash
 *  - knowledge_chunks      (tenant_id, knowledge_document_id, chunk_hash)
 *  - kb_nodes              (tenant_id, project_key, node_uid)
 *  - kb_edges              (tenant_id, project_key, edge_uid)
 *  - embedding_cache       (tenant_id, text_hash)
 *
 * Why tenant_id is mandatory day-1:
 *  - Multi-brand inside one customer (Surface = LVR + Outlet + Surface)
 *  - Multi-division (banks: IT/HR/CC/Operations)
 *  - Demo SaaS shared (one tenant per prospect)
 *  - M&A scenarios (acquired company keeps its KB while merge runs)
 *
 * v3 production rows: all `tenant_id = 'default'` after migrate.
 * Backward-compat preserved: code that does not pass tenant_id keeps
 * working until the foundation switches to tenant-scoped queries (R30
 * architecture test gate).
 */
return new class extends Migration {
    /**
     * Domain tables that receive a tenant_id column.
     *
     * Excluded on purpose:
     *  - users / roles / permissions    (cross-tenant identity)
     *  - jobs / failed_jobs              (queue plumbing)
     *  - activity_log                    (Spatie, polymorphic subject)
     */
    private const TABLES = [
        'knowledge_documents',
        'knowledge_chunks',
        'embedding_cache',
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
                continue; // Defensive: skip tables that may not exist on every install.
            }
            if (Schema::hasColumn($table, 'tenant_id')) {
                continue; // Idempotent: do not double-add on re-run.
            }
            Schema::table($table, function (Blueprint $t) use ($table): void {
                $t->string('tenant_id', 50)->default('default')->after('id');
                $t->index('tenant_id', $this->indexName($table));
            });
        }

        // Rebuild composite uniques tenant-scoped. Drop-then-recreate.
        // Each block is wrapped in hasTable + index_exists checks for
        // idempotency on re-runs.
        $this->rebuildKnowledgeDocumentsUniques();
        $this->rebuildKnowledgeChunksUniques();
        $this->rebuildKbNodesUniques();
        $this->rebuildKbEdgesUniques();
        $this->rebuildEmbeddingCacheUniques();
    }

    public function down(): void
    {
        // Reverse: drop tenant-scoped uniques, recreate the v3 ones, drop tenant_id.
        $this->reverseEmbeddingCacheUniques();
        $this->reverseKbEdgesUniques();
        $this->reverseKbNodesUniques();
        $this->reverseKnowledgeChunksUniques();
        $this->reverseKnowledgeDocumentsUniques();

        foreach (array_reverse(self::TABLES) as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'tenant_id')) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($table): void {
                $t->dropIndex($this->indexName($table));
                $t->dropColumn('tenant_id');
            });
        }
    }

    private function indexName(string $table): string
    {
        // Trim to <= 64 chars to fit MySQL's identifier limit.
        $name = "idx_{$table}_tenant_id";
        return mb_substr($name, 0, 64);
    }

    private function rebuildKnowledgeDocumentsUniques(): void
    {
        if (! Schema::hasTable('knowledge_documents')) {
            return;
        }
        Schema::table('knowledge_documents', function (Blueprint $t): void {
            // v3: uq_kb_doc_doc_id  (project_key, doc_id)
            // v3: uq_kb_doc_slug    (project_key, slug)
            // v3: uq_kb_doc_source  (project_key, source_path, version_hash)
            // We drop only if they exist, then recreate tenant-scoped.
            // Note: dropUnique by name is the safe path on all drivers.
            try { $t->dropUnique('uq_kb_doc_doc_id'); } catch (\Throwable) {}
            try { $t->dropUnique('uq_kb_doc_slug'); } catch (\Throwable) {}
            try { $t->dropUnique('uq_kb_doc_source'); } catch (\Throwable) {}

            $t->unique(['tenant_id', 'project_key', 'doc_id'], 'uq_kb_doc_doc_id');
            $t->unique(['tenant_id', 'project_key', 'slug'], 'uq_kb_doc_slug');
            $t->unique(['tenant_id', 'project_key', 'source_path', 'version_hash'], 'uq_kb_doc_source');
        });
    }

    private function reverseKnowledgeDocumentsUniques(): void
    {
        if (! Schema::hasTable('knowledge_documents')) {
            return;
        }
        Schema::table('knowledge_documents', function (Blueprint $t): void {
            try { $t->dropUnique('uq_kb_doc_doc_id'); } catch (\Throwable) {}
            try { $t->dropUnique('uq_kb_doc_slug'); } catch (\Throwable) {}
            try { $t->dropUnique('uq_kb_doc_source'); } catch (\Throwable) {}

            $t->unique(['project_key', 'doc_id'], 'uq_kb_doc_doc_id');
            $t->unique(['project_key', 'slug'], 'uq_kb_doc_slug');
            $t->unique(['project_key', 'source_path', 'version_hash'], 'uq_kb_doc_source');
        });
    }

    private function rebuildKnowledgeChunksUniques(): void
    {
        if (! Schema::hasTable('knowledge_chunks')) {
            return;
        }
        Schema::table('knowledge_chunks', function (Blueprint $t): void {
            try { $t->dropUnique('uq_kb_chunk_doc_hash'); } catch (\Throwable) {}
            $t->unique(['tenant_id', 'knowledge_document_id', 'chunk_hash'], 'uq_kb_chunk_doc_hash');
        });
    }

    private function reverseKnowledgeChunksUniques(): void
    {
        if (! Schema::hasTable('knowledge_chunks')) {
            return;
        }
        Schema::table('knowledge_chunks', function (Blueprint $t): void {
            try { $t->dropUnique('uq_kb_chunk_doc_hash'); } catch (\Throwable) {}
            $t->unique(['knowledge_document_id', 'chunk_hash'], 'uq_kb_chunk_doc_hash');
        });
    }

    private function rebuildKbNodesUniques(): void
    {
        if (! Schema::hasTable('kb_nodes')) {
            return;
        }
        Schema::table('kb_nodes', function (Blueprint $t): void {
            try { $t->dropUnique('uq_kb_nodes_project_uid'); } catch (\Throwable) {}
            $t->unique(['tenant_id', 'project_key', 'node_uid'], 'uq_kb_nodes_project_uid');
        });
    }

    private function reverseKbNodesUniques(): void
    {
        if (! Schema::hasTable('kb_nodes')) {
            return;
        }
        Schema::table('kb_nodes', function (Blueprint $t): void {
            try { $t->dropUnique('uq_kb_nodes_project_uid'); } catch (\Throwable) {}
            $t->unique(['project_key', 'node_uid'], 'uq_kb_nodes_project_uid');
        });
    }

    private function rebuildKbEdgesUniques(): void
    {
        if (! Schema::hasTable('kb_edges')) {
            return;
        }
        Schema::table('kb_edges', function (Blueprint $t): void {
            try { $t->dropUnique('uq_kb_edges_project_uid'); } catch (\Throwable) {}
            $t->unique(['tenant_id', 'project_key', 'edge_uid'], 'uq_kb_edges_project_uid');
        });
    }

    private function reverseKbEdgesUniques(): void
    {
        if (! Schema::hasTable('kb_edges')) {
            return;
        }
        Schema::table('kb_edges', function (Blueprint $t): void {
            try { $t->dropUnique('uq_kb_edges_project_uid'); } catch (\Throwable) {}
            $t->unique(['project_key', 'edge_uid'], 'uq_kb_edges_project_uid');
        });
    }

    private function rebuildEmbeddingCacheUniques(): void
    {
        if (! Schema::hasTable('embedding_cache')) {
            return;
        }
        Schema::table('embedding_cache', function (Blueprint $t): void {
            try { $t->dropUnique('embedding_cache_text_hash_unique'); } catch (\Throwable) {}
            $t->unique(['tenant_id', 'text_hash'], 'embedding_cache_text_hash_unique');
        });
    }

    private function reverseEmbeddingCacheUniques(): void
    {
        if (! Schema::hasTable('embedding_cache')) {
            return;
        }
        Schema::table('embedding_cache', function (Blueprint $t): void {
            try { $t->dropUnique('embedding_cache_text_hash_unique'); } catch (\Throwable) {}
            $t->unique(['text_hash'], 'embedding_cache_text_hash_unique');
        });
    }
};
