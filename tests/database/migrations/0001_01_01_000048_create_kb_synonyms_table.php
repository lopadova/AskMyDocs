<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v8.7/W1 — SQLite test mirror of
 * `database/migrations/2026_06_02_000001_create_kb_synonyms_table.php`.
 * `tests/database/migrations/` is the only migration path the SQLite test
 * runner loads (see TestCase::loadMigrationsFrom), so every production
 * create-table migration needs a mirror here. No vector columns, so this
 * is byte-for-byte equivalent to the production migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kb_synonyms', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('tenant_id', 50)->default('default')->index();
            $table->string('project_key', 120)->index();
            $table->string('term', 200);
            $table->json('synonyms');
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'project_key', 'term'], 'uq_kb_synonyms_tenant_project_term');
            $table->index(['tenant_id', 'project_key', 'enabled'], 'ix_kb_synonyms_tenant_project_enabled');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_synonyms');
    }
};
