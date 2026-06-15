<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v8.13/P11 — evidence-risk-review append-only review log (padosoft/laravel-
 * evidence-risk-review v1.1). Mirrors the package's published migration,
 * including the v1.1.0 `tenant_id` + `max_verdict` columns. The host runs the
 * `database` review-log store so the admin review-log surface has data; every
 * row is stamped with the active tenant by the bound TenantResolver (R30).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('evidence_risk_review_logs')) {
            return;
        }

        Schema::create('evidence_risk_review_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('review_id')->index();
            $table->string('artifact_id')->index();
            $table->string('profile_key')->index();
            $table->string('tenant_id')->nullable()->index();
            $table->string('max_verdict')->default('keep')->index();
            $table->decimal('risk_score', 5, 4)->default(0);
            $table->json('findings');
            $table->json('claim_verdicts');
            $table->json('source_tiers');
            $table->json('budget');
            $table->json('artifact');
            $table->json('options');
            $table->json('metadata');
            $table->timestamp('reviewed_at');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evidence_risk_review_logs');
    }
};
