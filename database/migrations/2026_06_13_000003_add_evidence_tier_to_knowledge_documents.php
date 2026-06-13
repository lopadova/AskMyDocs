<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * v8.11/P1b (AutoSci #67) — the evidence-strength axis.
 *
 * `evidence_tier` records what KIND of external evidence a doc's claims rest on
 * (guideline / peer_reviewed / official / preprint / news / blog / search_hint /
 * unverified — see App\Support\Canonical\EvidenceTier). Nullable, default null =
 * "not assessed" (treated as unverified for ranking) — back-compat: every pre-
 * v8.11.2 row is unaffected and behaves exactly as before.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('knowledge_documents', function (Blueprint $table) {
            $table->string('evidence_tier', 32)->nullable()->after('generation_source')->index();
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_documents', function (Blueprint $table) {
            $table->dropIndex('knowledge_documents_evidence_tier_index');
            $table->dropColumn('evidence_tier');
        });
    }
};
