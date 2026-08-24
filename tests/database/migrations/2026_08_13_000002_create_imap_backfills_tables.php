<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('imap_backfills', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('tenant_id', 50)->default('default');
            $table->unsignedBigInteger('connector_installation_id');
            $table->string('status', 24)->default('discovering');
            $table->json('settings_json')->nullable();
            $table->unsignedInteger('batch_size')->default(100);
            $table->unsignedBigInteger('total_messages')->default(0);
            $table->unsignedBigInteger('processed_messages')->default(0);
            $table->unsignedBigInteger('dispatched_documents')->default(0);
            $table->unsignedInteger('total_windows')->default(0);
            $table->unsignedInteger('completed_windows')->default(0);
            $table->timestamp('cutoff_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->json('error_json')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'connector_installation_id', 'status']);
            $table->index(['tenant_id', 'status', 'updated_at']);
            $table->foreign('connector_installation_id', 'fk_imap_backfills_install')
                ->references('id')
                ->on('connector_installations')
                ->cascadeOnDelete();
        });

        Schema::create('imap_backfill_windows', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('tenant_id', 50)->default('default');
            $table->unsignedBigInteger('imap_backfill_id');
            $table->unsignedBigInteger('connector_installation_id');
            $table->string('mailbox', 512);
            $table->date('window_start');
            $table->date('window_end');
            $table->string('status', 24)->default('pending');
            $table->unsignedBigInteger('snapshot_uid_validity')->default(0);
            $table->unsignedBigInteger('snapshot_max_uid')->default(0);
            $table->unsignedBigInteger('last_uid')->default(0);
            $table->unsignedBigInteger('expected_messages')->default(0);
            $table->unsignedBigInteger('processed_messages')->default(0);
            $table->unsignedBigInteger('dispatched_documents')->default(0);
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->json('error_json')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'imap_backfill_id', 'mailbox', 'window_start', 'window_end']);
            $table->index(['tenant_id', 'imap_backfill_id', 'status', 'next_attempt_at']);
            $table->index(['tenant_id', 'status', 'heartbeat_at']);
            $table->foreign('imap_backfill_id', 'fk_imap_windows_backfill')
                ->references('id')
                ->on('imap_backfills')
                ->cascadeOnDelete();
            $table->foreign('connector_installation_id', 'fk_imap_windows_install')
                ->references('id')
                ->on('connector_installations')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('imap_backfill_windows');
        Schema::dropIfExists('imap_backfills');
    }
};
