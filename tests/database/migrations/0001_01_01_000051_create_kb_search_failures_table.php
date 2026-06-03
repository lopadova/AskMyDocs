<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v8.8/W4 — SQLite test mirror of
 * `database/migrations/2026_06_02_000004_create_kb_search_failures_table.php`.
 * `tests/database/migrations/` is the only path the SQLite test runner loads
 * (TestCase::loadMigrationsFrom). Schema-equivalent (no vector columns).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kb_search_failures', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('tenant_id', 50)->default('default')->index();
            $table->string('project_key', 120)->default('');
            $table->char('query_hash', 64);
            $table->string('normalized_query', 500);
            $table->text('query_text');
            $table->string('reason', 40);
            $table->unsignedInteger('occurrences')->default(0);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'project_key', 'query_hash', 'reason'], 'uq_kb_search_failures');
            $table->index(['tenant_id', 'resolved_at', 'occurrences'], 'ix_kb_search_failures_rank');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_search_failures');
    }
};
