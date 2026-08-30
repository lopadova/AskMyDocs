<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * v8.33 / ADR 0028 phase 2 — when a source last dictated who may read this
 * document.
 *
 * Restriction cannot be inferred from the presence of mirrored ACL rows, and
 * the reason is the case that matters most. A source may report a permission
 * list naming only people this application cannot place — external
 * collaborators, a group with no internal counterpart — or name nobody at
 * all. Both are COMPLETE lists, and both produce zero mirrored rows.
 *
 * Reading "no rows" as "no restriction" would leave exactly those documents
 * visible to the whole project: the ones whose readers are least likely to be
 * colleagues. That is the over-sharing this phase exists to remove, and it
 * would have been reintroduced by the cheapest possible implementation.
 *
 * So the fact is recorded on the document. Null means no source has ever
 * spoken for it, which is every document that predates this and every corpus
 * whose connectors do not read permissions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_documents', function (Blueprint $table) {
            $table->timestamp('source_acl_enforced_at')->nullable()->after('provenance_tier');

            // The global scope reads this on every document query, and the
            // overwhelmingly common value is NULL.
            $table->index('source_acl_enforced_at', 'ix_kb_doc_source_acl');
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_documents', function (Blueprint $table) {
            $table->dropIndex('ix_kb_doc_source_acl');
            $table->dropColumn('source_acl_enforced_at');
        });
    }
};
