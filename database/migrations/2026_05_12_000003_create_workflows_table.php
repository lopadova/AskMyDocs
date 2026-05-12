<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v4.7/W2 — `workflows` table.
 *
 * Reusable prompt templates: `type=assistant` for chat workflows,
 * `type=tabular` for table extraction workflows. Shared firm-wide via
 * the {@see workflow_shares} pivot.
 *
 * `is_system` marks the 15 built-in templates seeded by
 * {@see \Database\Seeders\BuiltInWorkflowSeeder} — those rows are
 * never owned by a regular user (user_id remains nullable for system
 * rows) and cannot be deleted from the API.
 *
 * R31: tenant_id mandatory, indexed.
 * R30: every read query MUST scope to TenantContext::current().
 *
 * `tabular_reviews.workflow_id` (created in W1 as a nullable bigint
 * without FK) is kept as an application-enforced reference — no DB-level
 * FK is added here because the W1 column might already contain legacy
 * values from W1's pre-W2 lifecycle.
 *
 * Copilot iter 1 — system-template uniqueness: `BuiltInWorkflowSeeder`
 * uses `(tenant_id, title, is_system=true)` as the natural key but no
 * DB-level partial unique enforces it. The trade-off is intentional:
 * (a) the seeder runs single-threaded inside `php artisan migrate
 * --seed` / `db:seed`, so concurrent seed races cannot occur in any
 * supported deployment; (b) PostgreSQL supports partial unique
 * indexes (`UNIQUE(...) WHERE is_system`) but MySQL ≤8.0 and SQLite
 * (used in tests) do not, so adding the partial would require
 * conditional schema branches that diverge between production and
 * test migrations. The application-level guarantee
 * (`Workflow::updateOrCreate(...)` keyed on the triple) is sufficient
 * for the operational pattern. If a future PR introduces
 * parallel-tenant seed jobs, revisit with a Postgres-only partial
 * unique + a UniqueConstraintViolationException catch in the seeder.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('workflows', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('tenant_id', 50)->default('default')->index('idx_workflows_tenant_id');
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->cascadeOnDelete();
            $table->string('title', 200);
            $table->string('type', 20);
            $table->text('prompt_md');
            $table->json('columns_config')->nullable();
            $table->string('practice', 30)->default('generic');
            $table->boolean('is_system')->default(false);
            $table->timestamps();

            $table->index(['tenant_id', 'user_id'], 'idx_workflows_tenant_user');
            $table->index(['tenant_id', 'type'], 'idx_workflows_tenant_type');
            $table->index(['tenant_id', 'is_system'], 'idx_workflows_tenant_system');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflows');
    }
};
