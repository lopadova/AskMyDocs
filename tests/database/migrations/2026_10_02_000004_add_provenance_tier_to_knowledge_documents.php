<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SQLite-compatible mirror of
// database/migrations/2026_10_02_000004_add_provenance_tier_to_knowledge_documents.php
// Runs after the evidence-tier mirror (000101), which adds `evidence_tier`.
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
