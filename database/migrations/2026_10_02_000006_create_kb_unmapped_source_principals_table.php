<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * v8.33 / ADR 0028 phase 2 — the principals a source named and this
 * application could not place.
 *
 * Mirroring upstream permissions only works for principals that resolve to a
 * subject here. A great many do not, and for entirely ordinary reasons: an
 * external collaborator, a Google group with no counterpart role, a
 * domain-wide share, a colleague who has not signed up yet. Those are not
 * errors and they are not rare.
 *
 * The dangerous thing to do with them is nothing. Silently dropping an
 * unresolved principal means the mirrored allow-list is narrower than the
 * source's, so a person who legitimately has access upstream quietly loses
 * it here — and nobody finds out, because a missing row looks exactly like a
 * share that was never made. Silently granting instead would be the same
 * mistake pointed the other way, and worse.
 *
 * So they are recorded, and an operator decides. This table is that queue.
 * It holds a question, never a permission: nothing here grants anything, and
 * resolving an entry means creating an ordinary manual ACL row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kb_unmapped_source_principals', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('tenant_id', 50)->default('default')->index();

            $table->foreignId('knowledge_document_id')
                ->constrained('knowledge_documents')
                ->cascadeOnDelete();

            // Denormalised from the document so the triage queue can be
            // filtered per project without joining, which is the only way it
            // is ever read.
            $table->string('project_key', 120)->index();

            // user | group | domain | anyone, as the SOURCE described it.
            // Deliberately not mapped onto this application's subject types:
            // the whole point of the row is that no such mapping was found.
            $table->string('principal_type', 32);
            // An email, a group address, a domain, or empty for "anyone".
            $table->string('principal_external_id', 320);
            // allow | deny, carried through so an operator sees whether the
            // source was granting or withholding.
            $table->string('effect', 8)->default('allow');

            // pending | ignored. An ignored entry stays visible but stops
            // counting as outstanding work, so a decision taken once is not
            // re-asked on every sync.
            $table->string('status', 16)->default('pending');

            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            // One row per principal per document. Reconciliation upserts on
            // this key, so a principal reported on every sync does not
            // accumulate duplicates and does not lose the operator's decision.
            $table->unique(
                ['tenant_id', 'knowledge_document_id', 'principal_type', 'principal_external_id'],
                'uq_kb_unmapped_principal'
            );

            // The queue's own read: outstanding work for a tenant.
            $table->index(['tenant_id', 'status'], 'ix_kb_unmapped_tenant_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_unmapped_source_principals');
    }
};
