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
 * **Composite unique rebuild deliberately deferred**:
 * The v3 composite uniques (e.g. `uq_kb_nodes_project_uid` on
 * `(project_key, node_uid)`) have FOREIGN KEY dependents on Postgres
 * (the kb_edges composite FK references this unique). Dropping the
 * unique requires CASCADE, which Laravel's Blueprint API does not
 * expose cleanly. Until the first customer actually uses a non-default
 * tenant_id, the v3 unique on (project_key, node_uid) is sufficient
 * because all rows have `tenant_id = 'default'` — the v3 unique
 * collapses to `(default, project_key, node_uid)` semantically.
 *
 * When the first multi-tenant customer onboards, a follow-up migration
 * (v4.x) will rebuild uniques tenant-scoped using `DB::statement` with
 * raw `DROP CONSTRAINT ... CASCADE` + `ADD CONSTRAINT` + FK rebuild.
 *
 * For now: column add only. Application layer (R30) enforces
 * tenant boundary via `forTenant()` scope on every query.
 *
 * Why tenant_id is mandatory day-1:
 *  - Multi-brand inside one customer (Surface = LVR + Outlet + Surface)
 *  - Multi-division (banks: IT/HR/CC/Operations)
 *  - Demo SaaS shared (one tenant per prospect)
 *  - M&A scenarios (acquired company keeps its KB while merge runs)
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
    }

    public function down(): void
    {
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
};
