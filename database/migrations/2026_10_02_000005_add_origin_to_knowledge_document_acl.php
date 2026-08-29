<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * v8.33 / ADR 0028 phase 2 — where an ACL row came from.
 *
 * Mirroring source permissions means a sync has to be able to REMOVE rows
 * again: a mirror that only ever adds is a slow leak, because a share
 * revoked upstream would stay granted here forever. But reconciliation must
 * not touch what an operator set by hand — deleting a manual grant because
 * the upstream ACL no longer mentions it would be the same bug pointed the
 * other way.
 *
 * `origin` is what lets reconciliation tell them apart:
 *
 *   - `manual`        — a person set this. Never touched by a sync.
 *   - `source-mirror` — reflected from upstream. Owned by reconciliation,
 *                       and therefore deletable by it.
 *
 * Existing rows default to `manual`, which is what every row is today: they
 * were all created by hand or by a seeder, and none of them may be swept
 * away by the first sync that runs after this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_document_acl', function (Blueprint $table) {
            $table->string('origin', 32)->default('manual')->after('effect');

            // Reconciliation's own query: "every mirrored row for this
            // document". Without the index it is a scan of the ACL table on
            // every synced document.
            $table->index(
                ['knowledge_document_id', 'origin'],
                'ix_kb_doc_acl_doc_origin'
            );
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_document_acl', function (Blueprint $table) {
            $table->dropIndex('ix_kb_doc_acl_doc_origin');
            $table->dropColumn('origin');
        });
    }
};
