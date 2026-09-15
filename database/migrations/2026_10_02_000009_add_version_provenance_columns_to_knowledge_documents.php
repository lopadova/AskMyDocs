<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * v8.36 / ADR 0030 §4 — who created a document version, why, and the hash
 * of the stored artifact.
 *
 * All three are nullable so every existing row is a valid "unknown actor"
 * version; no backfill invents an actor. `content_hash` is the SHA-256 of
 * the stored artifact: equal to `document_hash` by construction (the
 * artifact IS the converted Markdown the version hash was computed from) —
 * an integrity check on the file, never a second identity; a later
 * correction is an ordinary new version (ADR 0030 §4/§7).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Idempotent: this file was renamed from the `..._000008_...` prefix
        // it shared with another migration, so an environment that already
        // ran it under the old name re-enters here — each column is added
        // only when absent (a duplicate-column error would abort the batch).
        Schema::table('knowledge_documents', function (Blueprint $table) {
            if (! Schema::hasColumn('knowledge_documents', 'version_actor')) {
                $table->string('version_actor', 191)->nullable()->after('markdown_path');
            }
            if (! Schema::hasColumn('knowledge_documents', 'version_reason')) {
                $table->string('version_reason', 1024)->nullable()->after('version_actor');
            }
            if (! Schema::hasColumn('knowledge_documents', 'content_hash')) {
                $table->string('content_hash', 64)->nullable()->after('version_reason');
            }
        });

        // …and the history is reconciled, not only the schema. A database that
        // ran the old filename keeps a `migrations` row naming a file that no
        // longer exists: `migrate` ignores it, but `migrate:rollback` resolves
        // every name in the batch and would fail on the missing one — or, if
        // it were ever restored, drop these columns twice. The stale entry
        // names exactly the columns THIS migration owns, so removing it leaves
        // one record for one schema change. Best-effort: a deployment that
        // never ran the old name has nothing to delete, and a failure here
        // must not abort a migration whose schema work already succeeded.
        try {
            DB::table('migrations')
                ->where('migration', '2026_10_02_000008_add_version_provenance_columns_to_knowledge_documents')
                ->delete();
        } catch (\Throwable) {
            // No `migrations` table (a schema:dump install) or a driver that
            // refuses the write: the schema is correct either way.
        }
    }

    public function down(): void
    {
        Schema::table('knowledge_documents', function (Blueprint $table) {
            $table->dropColumn(['version_actor', 'version_reason', 'content_hash']);
        });
    }
};
