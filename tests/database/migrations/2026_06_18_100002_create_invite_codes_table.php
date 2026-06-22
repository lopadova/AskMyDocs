<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Invite system — InviteCode (the redeemable token).
 *
 * Canonical entity from docs/04-data-model.md. The fields that drive the
 * atomicity contract (docs/07-redemption-flow.md) are `state`, `max_uses`,
 * and `current_uses`: the claim that makes `current_uses == max_uses`
 * transitions `active → exhausted` in the SAME atomic statement.
 *
 *   CHECK(current_uses <= max_uses)   — pgsql backstop against over-redemption
 *   UNIQUE(tenant_id, code)           — normalized codes are unique per tenant
 *
 * `code` is stored in the canonical normalized form (uppercased, separators
 * stripped, Crockford input-folded) — see App\Services\Invite\CodeNormalizer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invite_codes', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('tenant_id', 50)->default('default')->index();

            $table->unsignedBigInteger('campaign_id')->nullable(); // null = standalone code
            $table->string('code', 64); // normalized form
            $table->string('code_kind', 10)->default('random'); // random | vanity | signed
            $table->string('state', 12)->default('active'); // active | redeemed | exhausted | expired | revoked

            $table->unsignedInteger('max_uses')->default(1);
            $table->unsignedInteger('current_uses')->default(0);

            $table->unsignedBigInteger('issuer_id')->nullable(); // Account FK
            $table->timestamp('expires_at')->nullable(); // null = never

            $table->json('payload')->nullable();   // signed-code carried data
            $table->string('signature')->nullable(); // signed-code HMAC
            $table->json('metadata')->nullable();

            // Per-code provisioning override (same shape as invite_campaigns.grant).
            // null = inherit the campaign's grant; non-null = this code's grant wins.
            $table->json('grant')->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'code'], 'uq_invite_codes_tenant_code');
            $table->index(['tenant_id', 'state'], 'ix_invite_codes_tenant_state');
            $table->index(['campaign_id', 'state'], 'ix_invite_codes_campaign_state');
            $table->index('expires_at', 'ix_invite_codes_expires_at');

            $table->foreign('campaign_id')
                ->references('id')->on('invite_campaigns')
                ->cascadeOnUpdate()->nullOnDelete();
            $table->foreign('issuer_id')
                ->references('id')->on('users')
                ->cascadeOnUpdate()->nullOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE invite_codes ADD CONSTRAINT chk_invite_codes_uses '
                . 'CHECK (current_uses <= max_uses)'
            );
            DB::statement(
                "ALTER TABLE invite_codes ADD CONSTRAINT chk_invite_codes_state "
                . "CHECK (state IN ('active','redeemed','exhausted','expired','revoked'))"
            );
            DB::statement(
                "ALTER TABLE invite_codes ADD CONSTRAINT chk_invite_codes_kind "
                . "CHECK (code_kind IN ('random','vanity','signed'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('invite_codes');
    }
};
