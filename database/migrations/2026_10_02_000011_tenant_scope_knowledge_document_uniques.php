<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v8.36 / R30 + R31 — rebuild the three `knowledge_documents` composite
 * uniques so they START with `tenant_id`.
 *
 * The v3-era indexes were keyed on `project_key` alone:
 *
 *   uq_kb_doc_version  (project_key, source_path, version_hash)
 *   uq_kb_doc_doc_id   (project_key, doc_id)
 *   uq_kb_doc_slug     (project_key, slug)
 *
 * `project_key` is NOT a tenant boundary — two tenants can legitimately run
 * a project called `eng` — so those indexes made the row identity global
 * while every read/write path scopes by `tenant_id` (R30). The two sides
 * disagreed in the direction that hurts: the ingestor's tenant-scoped
 * `updateOrCreate` lookup finds no row for the second tenant and the insert
 * then dies on the global unique, and the restore path's conflict probe
 * cannot see a holder the database will still reject.
 *
 * Widening a unique can never fail on existing data: every tuple the old
 * index admitted is still unique under the new one. Same shape as
 * 2026_05_26_000001 (kb_tags / project_memberships) and 2026_05_26_000002
 * (admin_insights_snapshots), which rebuilt their uniques for the same
 * reason. The `kb_nodes` / `kb_edges` pair stays deferred: its unique is
 * the target of a composite FK and needs the raw DROP CONSTRAINT ...
 * CASCADE + FK rebuild the note in 2026_05_26_000001 describes.
 *
 * No table's FK references any of the three indexes touched here
 * (`knowledge_chunks`, `knowledge_document_tags` and
 * `knowledge_document_acl` all reference `knowledge_documents.id`), so the
 * portable Blueprint API is enough.
 */
return new class extends Migration
{
    /**
     * @var list<array{0: string, 1: list<string>, 2: string, 3: list<string>}>
     *                                                                         [legacy name, legacy columns, tenant-scoped name, tenant-scoped columns]
     */
    private array $uniques = [
        ['uq_kb_doc_version', ['project_key', 'source_path', 'version_hash'], 'uq_kb_doc_tenant_version', ['tenant_id', 'project_key', 'source_path', 'version_hash']],
        ['uq_kb_doc_doc_id', ['project_key', 'doc_id'], 'uq_kb_doc_tenant_doc_id', ['tenant_id', 'project_key', 'doc_id']],
        ['uq_kb_doc_slug', ['project_key', 'slug'], 'uq_kb_doc_tenant_slug', ['tenant_id', 'project_key', 'slug']],
    ];

    public function up(): void
    {
        $this->rebuild(static fn (array $spec): array => [$spec[0], $spec[2], $spec[3]]);
    }

    public function down(): void
    {
        $this->rebuild(static fn (array $spec): array => [$spec[2], $spec[0], $spec[1]]);
    }

    /**
     * Drop `$from` when it is really there and create `$to` when it is not:
     * both halves are conditional because the test schema never created
     * `uq_kb_doc_version` at all, and a re-run must stay a no-op.
     *
     * @param  \Closure(array{0: string, 1: list<string>, 2: string, 3: list<string>}): array{0: string, 1: string, 2: list<string>}  $direction
     */
    private function rebuild(\Closure $direction): void
    {
        if (! Schema::hasTable('knowledge_documents') || ! Schema::hasColumn('knowledge_documents', 'tenant_id')) {
            return;
        }

        $existing = $this->indexNames();
        foreach ($this->uniques as $spec) {
            [$from, $to, $columns] = $direction($spec);
            Schema::table('knowledge_documents', function (Blueprint $table) use ($existing, $from, $to, $columns): void {
                if (in_array($from, $existing, true)) {
                    $table->dropUnique($from);
                }
                if (! in_array($to, $existing, true)) {
                    $table->unique($columns, $to);
                }
            });
        }
    }

    /**
     * @return list<string>
     */
    private function indexNames(): array
    {
        return array_values(array_map(
            static fn (array $index): string => (string) $index['name'],
            Schema::getIndexes('knowledge_documents'),
        ));
    }
};
