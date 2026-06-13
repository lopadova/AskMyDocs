<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SQLite-compatible mirror of
// database/migrations/2026_06_13_000003_add_evidence_tier_to_knowledge_documents.php
// Runs after the auto-wiki columns mirror (000099) which adds `generation_source`.
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
