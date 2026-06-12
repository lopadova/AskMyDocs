<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQLite test mirror of database/migrations/2026_06_12_000001_create_kb_ingest_batches_table.php.
 * No vector columns, so the body is identical to the production migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kb_ingest_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('tenant_id', 50)->default('default')->index();
            $table->string('project_key', 120);
            $table->string('sub_path', 500)->nullable();
            $table->string('status', 20)->default('staged')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('committed_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_ingest_batches');
    }
};
