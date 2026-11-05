<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `kb_wiki_export_requests` — v8.38/W4b (ADR 0032 §5/§11/§12).
 *
 * Mirrored verbatim from the production migration (SQLite test-schema
 * convention).
 *
 * One row per async `POST /api/admin/kb/exports` (or `KbCreateExportTool`)
 * call. Tracks the queued → processing → completed|failed lifecycle of an
 * export bundle staged on the `kb.staging.disk` (ADR 0029's disk, reused —
 * NOT the sync CLI's `--output` path, and NOT a new disk of its own).
 *
 * `idempotency_key` is the DB-enforced half of ADR 0032 §11's idempotency
 * contract: `(tenant, principal, project, sha256 of normalised options,
 * corpus snapshot, authorization digest)`. A repeat request with an
 * unchanged key returns the existing row instead of re-exporting; any
 * element changing (new option, a newer document, a narrowed ACL) computes a
 * different key and starts a fresh export — see
 * `KbWikiExportRequestService::idempotencyKeyFor()`.
 *
 * `document_ids_json` is the ACL-scoped id set the export was actually
 * computed against, recorded so every DOWNLOAD (not just the original
 * request) can re-authorize the principal against it and answer 403
 * `export_invalidated` if any id is no longer visible — the second,
 * independent gate ADR 0032 §11 requires because a cached idempotency key
 * can outlive the state it was computed from.
 *
 * `expires_at` is swept by `kb:prune-wiki-exports` (hourly,
 * `onOneServer()->withoutOverlapping()`) — a knob and a sweep of its own,
 * deliberately not the staging batches' `kb:prune-staging-batches` (ADR
 * 0032 §12: the two retention windows govern different artifacts with
 * different lifetimes).
 *
 * Tenant-aware (R30/R31): `tenant_id` defaults to 'default', every
 * composite index starts with it. UUID primary key (HasUuids) so the id is
 * an opaque, non-enumerable token in `/api/admin/kb/exports/{id}` — same
 * rationale as `kb_ingest_batches`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kb_wiki_export_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('tenant_id', 50)->default('default')->index();
            $table->string('project_key', 120);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            // queued | processing | completed | failed | expired
            $table->string('status', 32)->default('queued')->index();
            $table->json('options_json');
            // Unique PER TENANT — two tenants may legitimately compute the
            // same key from different corpora with the same options.
            $table->string('idempotency_key', 64);
            // The ACL-scoped document ids the export was computed against
            // (§11's download-time re-authorization set). Null until the
            // job has actually resolved the document list.
            $table->json('document_ids_json')->nullable();
            $table->string('storage_disk', 60)->nullable();
            $table->string('storage_path', 500)->nullable();
            $table->unsignedBigInteger('document_count')->nullable();
            $table->boolean('partial')->default(false);
            $table->string('error_message', 1000)->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'idempotency_key'], 'uq_kb_wiki_export_tenant_idempotency');
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_wiki_export_requests');
    }
};
