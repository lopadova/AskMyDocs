<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Editorial audit trail (see ADR 0003).
 *
 * One row per promotion / update / deprecation / rejection-injection event.
 * Immutable (no updated_at). Retention policy: keep indefinitely; customers
 * with data-residency rules can add their own retention job. The table
 * deliberately has no FK to knowledge_documents so rows survive hard deletes
 * and give forensics access to deleted canonical history.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('kb_canonical_audit', function (Blueprint $table) {
            $table->id();
            $table->string('project_key', 120)->index();
            $table->string('doc_id', 128)->nullable()->index();
            $table->string('slug', 255)->nullable()->index();
            $table->string('event_type', 64)->index();  // promoted|updated|deprecated|superseded|rejected_injection_used|graph_rebuild
            $table->string('actor', 191);                // user id, command name, or "system"
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
