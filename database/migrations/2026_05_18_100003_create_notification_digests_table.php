<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v8.0/W1.1 — `notification_digests` table.
 *
 * Weekly aggregated digest payload per tenant (ADR 0012). One row
 * per (tenant, week_start_date). Populated incrementally by the
 * dispatcher: every event with delivery mode `aggregate` gets
 * upserted into the current week's payload instead of firing a
 * `NotifyUserJob` immediately.
 *
 * The `BuildWeeklyDigestCommand` cron then renders the digest from
 * the aggregated payload and sends it via the email channel to
 * subscribed users. After delivery, `sent_at` and
 * `recipients_count` are updated.
 *
 * Composite unique `(tenant_id, week_start_date)` makes the
 * incremental upsert idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_digests', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id', 50)->default('default')->index();
            $table->date('week_start_date');
            $table->json('payload');
            $table->timestamp('sent_at')->nullable();
            $table->unsignedInteger('recipients_count')->default(0);
            $table->timestamps();

            $table->unique(
                ['tenant_id', 'week_start_date'],
                'uq_notif_digests_tenant_week',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_digests');
    }
};
