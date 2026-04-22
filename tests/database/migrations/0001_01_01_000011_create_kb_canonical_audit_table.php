<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SQLite-compatible mirror of
// database/migrations/2026_04_22_000003_create_kb_canonical_audit_table.php
return new class extends Migration {
    public function up(): void
    {
        Schema::create('kb_canonical_audit', function (Blueprint $table) {
            $table->id();
            $table->string('project_key', 120)->index();
            $table->string('doc_id', 128)->nullable()->index();
            $table->string('slug', 255)->nullable()->index();
            $table->string('event_type', 64)->index();
            $table->string('actor', 191);
            $table->json('before_json')->nullable();
            $table->json('after_json')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['project_key', 'event_type'], 'idx_kb_audit_project_event');
            $table->index(['created_at'], 'idx_kb_audit_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_canonical_audit');
    }
};
