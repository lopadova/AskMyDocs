<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * v8.36 / ADR 0030 §4 — who created a document version, why, and the hash
 * of the stored artifact.
 *
 * All three are nullable so every existing row is a valid "unknown actor"
 * version; no backfill invents an actor. `content_hash` equals
 * `document_hash` until the first correction (v8.37) changes the artifact
 * without re-running conversion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_documents', function (Blueprint $table) {
            $table->string('version_actor', 191)->nullable()->after('markdown_path');
            $table->string('version_reason', 1024)->nullable()->after('version_actor');
            $table->string('content_hash', 64)->nullable()->after('version_reason');
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_documents', function (Blueprint $table) {
            $table->dropColumn(['version_actor', 'version_reason', 'content_hash']);
        });
    }
};
