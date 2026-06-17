<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v8.15/W3 — the in-app digest feed: a history of generated rich digests a user
 * can browse inside the SPA ("This week in your KB"), independent of email
 * delivery. One row per generated digest run, tenant-scoped (R30/R31).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('engagement_digest_feed', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('tenant_id', 50)->default('default')->index();
            $table->string('frequency', 16);           // weekly | monthly
            $table->date('period_start');
            $table->date('period_end');
            $table->json('payload');                   // DigestPayload::toArray()
            // Non-null (useCurrent default) — the feed's "latest" ordering and
            // digest:prune-feed retention both key on created_at.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'created_at'], 'ix_digest_feed_tenant_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('engagement_digest_feed');
    }
};
