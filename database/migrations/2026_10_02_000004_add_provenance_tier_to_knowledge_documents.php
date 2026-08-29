<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * v8.32 / ADR 0028 phase 1 — where a document's text came from.
 *
 * Ingestion records what a document IS and nothing about who authored it, so a
 * page written by staff and an email written by anyone who knows the address
 * are stored as the same kind of fact. Both become retrieval grounding on a
 * platform that also exposes tools to an agent.
 *
 * `provenance_tier` is the connector's declaration (see the
 * `DeclaresProvenance` capability on askmydocs-connector-base): one of
 * trusted-internal / untrusted-external / machine-generated.
 *
 * Nullable, default null = "no connector declaration", which is every row
 * written before this column existed and every connector that does not
 * implement the capability. Readers resolve null through
 * `ProvenanceTier::fromStorage()`, which maps absence to the trusted default
 * (preserving today's meaning exactly) while failing closed on any value it
 * does not recognise.
 *
 * Indexed because the first question this answers is an aggregate one — how
 * much of the corpus is externally authored — and the admin read-out groups
 * by it per project.
 *
 * This phase LABELS only. Nothing enforces on the value yet; that is v8.34,
 * and it is only testable against a corpus that is already labelled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_documents', function (Blueprint $table) {
            $table->string('provenance_tier', 32)->nullable()->after('evidence_tier')->index();
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_documents', function (Blueprint $table) {
            $table->dropIndex('knowledge_documents_provenance_tier_index');
            $table->dropColumn('provenance_tier');
        });
    }
};
