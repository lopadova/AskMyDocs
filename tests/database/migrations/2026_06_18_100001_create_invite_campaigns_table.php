<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Invite system — Campaign (issuing policy / container).
 *
 * Canonical entity from the invite-system handoff (docs/04-data-model.md).
 * A Campaign is the policy envelope a code is issued under: its type, its
 * activation window, its per-user / global caps, and its declarative reward
 * policy. Standalone codes (campaign_id NULL on invite_codes) are allowed.
 *
 * Tenant-aware per R30/R31: every invite entity carries `tenant_id` and every
 * composite unique starts with it, so two tenants can legitimately run a
 * campaign with the same `key`. `created_by` is an Account FK → the host
 * `users` table (cross-tenant identity, never tenant-scoped).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invite_campaigns', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('tenant_id', 50)->default('default')->index();

            // Stable machine identifier, unique per tenant.
            $table->string('key', 120);
            $table->string('name');
            $table->text('description')->nullable();

            // Issuing policy class.
            $table->string('type', 20); // single_use | multi_use | capacity | referral | waitlist_skip
            $table->string('status', 12)->default('draft'); // draft | active | paused | ended

            $table->unsignedInteger('max_redemptions_total')->nullable(); // null = unlimited
            $table->unsignedInteger('per_user_limit')->default(1);

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            // Declarative reward rules consumed by the reward engine (Phase 3).
            $table->json('reward_policy')->nullable();

            // Account FK — admin who created it. Cross-tenant identity.
            $table->unsignedBigInteger('created_by');

            $table->timestamps();

            $table->unique(['tenant_id', 'key'], 'uq_invite_campaigns_tenant_key');
            $table->index(['tenant_id', 'status'], 'ix_invite_campaigns_tenant_status');
            $table->index('created_by', 'ix_invite_campaigns_created_by');

            $table->foreign('created_by')
                ->references('id')->on('users')
                ->cascadeOnUpdate()->restrictOnDelete();
        });

        // CHECK constraints are a pgsql-side backstop; SQLite (tests) relies on
        // the application-layer guards. Mirrors the repo's "pgsql only, no-op
        // elsewhere" convention used for the FTS GIN index.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE invite_campaigns ADD CONSTRAINT chk_invite_campaigns_type "
                . "CHECK (type IN ('single_use','multi_use','capacity','referral','waitlist_skip'))"
            );
            DB::statement(
                "ALTER TABLE invite_campaigns ADD CONSTRAINT chk_invite_campaigns_status "
                . "CHECK (status IN ('draft','active','paused','ended'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('invite_campaigns');
    }
};
