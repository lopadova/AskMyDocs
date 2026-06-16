<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v8.15/W1 — KB Engagement & Intelligence Suite: the contribution-event log.
 *
 * An append-only record of who did what to the knowledge base: created /
 * modified / promoted / reviewed a document, answered a question, or had one of
 * their documents cited in a grounded answer. This is the raw material for
 * contributor analytics, "your impact" metrics, the digest, and the opt-in
 * gamification layer (W5).
 *
 * It is NOT a second ingestion/audit path — rows are appended by hooks on the
 * EXISTING ingest / promotion / chat-citation flows. `kb_canonical_audit` stays
 * the editorial forensic trail; this table is the engagement signal.
 *
 * Tenant-aware per R30/R31. The leaderboard / impact hot paths are covered by
 * the composite indexes below.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kb_contribution_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('tenant_id', 50)->default('default')->index();
            // The actor. Nullable: system-originated events (e.g. a citation of a
            // doc whose author left) still count toward the document's impact.
            $table->unsignedBigInteger('user_id')->nullable();
            // The document the contribution is about. Nullable + no FK so rows
            // survive a hard delete (impact history must outlive the doc).
            $table->unsignedBigInteger('document_id')->nullable();
            $table->string('project_key', 120)->default('');
            // created | modified | promoted | reviewed | answered | cited
            $table->string('event', 32);
            // Relative weight of the contribution (drives score; e.g. promoted > created > modified).
            $table->unsignedSmallInteger('weight')->default(1);
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();

            // Leaderboard / "your contributions" hot path: per-actor over time.
            $table->index(['tenant_id', 'user_id', 'created_at'], 'ix_kb_contrib_actor');
            // Activity rollups by event type over time.
            $table->index(['tenant_id', 'event', 'created_at'], 'ix_kb_contrib_event');
            // "Your impact" — citations/contributions for a given document.
            $table->index(['tenant_id', 'document_id'], 'ix_kb_contrib_document');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_contribution_events');
    }
};
