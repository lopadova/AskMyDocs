<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('flow_runs')) {
            return;
        }

        if (! Schema::hasTable('flow_approvals')) {
            Schema::create('flow_approvals', function (Blueprint $table): void {
                $table->string('id', 36)->primary();
                $table->string('run_id', 36);
                $table->string('step_name');
                $table->string('status', 32)->index();
                $table->string('token_hash', 64)->unique();
                $table->json('payload')->nullable();
                $table->json('actor')->nullable();
                $table->timestampTz('expires_at')->nullable()->index();
                $table->timestampTz('consumed_at')->nullable()->index();
                $table->timestampTz('decided_at')->nullable();
                $table->timestampsTz();

                $table->index(['run_id', 'status']);
                $table->index(['status', 'expires_at']);
                $table->foreign('run_id')->references('id')->on('flow_runs')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('flow_webhook_outbox')) {
            Schema::create('flow_webhook_outbox', function (Blueprint $table): void {
                $table->id();
                $table->string('run_id', 36)->nullable()->index();
                $table->string('approval_id', 36)->nullable()->index();
                $table->string('event')->index();
                $table->string('status', 32)->index();
                $table->json('payload')->nullable();
                $table->unsignedSmallInteger('attempts')->default(0);
                $table->unsignedSmallInteger('max_attempts')->default(3);
                $table->timestampTz('available_at')->nullable()->index();
                $table->timestampTz('delivered_at')->nullable();
                $table->timestampTz('failed_at')->nullable();
                $table->text('last_error')->nullable();
                $table->timestampsTz();

                $table->index(['status', 'available_at']);
                $table->index(['run_id', 'event']);
                $table->foreign('run_id')->references('id')->on('flow_runs')->cascadeOnDelete();
                $table->foreign('approval_id')->references('id')->on('flow_approvals')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_webhook_outbox');
        Schema::dropIfExists('flow_approvals');
    }
};
