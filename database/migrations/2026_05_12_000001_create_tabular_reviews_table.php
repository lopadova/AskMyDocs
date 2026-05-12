<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v4.7/W1 — `tabular_reviews` table.
 *
 * Spreadsheet-style document extraction reviews. Each row owns a column
 * configuration (`columns_config` JSON) that drives the extraction
 * pipeline; cells referencing this review live in `tabular_cells` with
 * a cascade-on-delete FK so destroying a review removes its grid
 * atomically. Laravel's `$table->json(...)` maps to Postgres `json` /
 * MySQL `json` / SQLite `text` — switch to `jsonb` (Postgres-only)
 * when GIN indexing on `columns_config` becomes load-bearing; today
 * the column is read whole-row in the controller, so `json` is fine.
 *
 * R31: `tenant_id` mandatory, indexed.
 * R30: every query must scope to TenantContext::current().
 *
 * `workflow_id` is intentionally nullable WITHOUT an FK constraint in
 * W1 because the `workflows` table is created in W2. Once W2 lands, a
 * follow-up migration can add the FK with ON DELETE SET NULL semantics.
 * The application enforces existence via FormRequest validation.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('tabular_reviews', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('tenant_id', 50)->default('default');
            $table->string('project_key', 120);
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->string('title', 200);
            $table->json('columns_config');
            // workflow_id deferred to W2 — stored as nullable bigint with
            // no constraint so the column exists day-1.
            $table->unsignedBigInteger('workflow_id')->nullable();
            $table->json('shared_with')->nullable();
            $table->string('practice', 100)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'project_key'], 'idx_tabular_reviews_tenant_project');
            $table->index(['tenant_id', 'user_id'], 'idx_tabular_reviews_tenant_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tabular_reviews');
    }
};
