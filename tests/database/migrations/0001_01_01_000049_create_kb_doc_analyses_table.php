<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v8.7/W3–W4 — SQLite test mirror of
 * `database/migrations/2026_06_02_000002_create_kb_doc_analyses_table.php`.
 * `tests/database/migrations/` is the only path the SQLite test runner
 * loads (TestCase::loadMigrationsFrom). Schema-equivalent (no vector
 * columns to swap).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kb_doc_analyses', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('tenant_id', 50)->default('default')->index();
            $table->string('project_key', 120)->index();
            $table->unsignedBigInteger('knowledge_document_id')->index();
            $table->string('doc_slug', 200)->nullable();
            $table->string('trigger', 20);
            $table->json('analysis_json');
            $table->unsignedInteger('suggestion_count')->default(0);
            $table->unsignedInteger('impacted_count')->default(0);
            $table->string('provider', 60)->nullable();
            $table->string('model', 120)->nullable();
            $table->string('status', 20)->default('completed');
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'knowledge_document_id', 'created_at'], 'ix_kb_doc_analyses_tenant_doc_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_doc_analyses');
    }
};
