<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rolling, incremental "session recap" for a conversation — a compact
 * summary (topics, open questions, gist) injected into the RAG system
 * prompt so the assistant keeps a sense of what has been discussed without
 * re-reading the full message history on every turn.
 *
 * A single nullable JSON column (not dedicated sub-columns): the shape is
 * consumed only by the prompt composer and the update job, never filtered/
 * aggregated in SQL, so there is no query-performance case for exploding it
 * into columns (unlike e.g. messages.confidence — see MessageController's
 * T3.1/T3.5 comments for that distinction). Additive to every existing
 * reader (R27): absent/null means "no recap yet", not an error.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->json('session_recap')->nullable()->after('importance');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropColumn('session_recap');
        });
    }
};
