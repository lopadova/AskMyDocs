<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pii_redactor_admin_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->string('event_type', 64);
            $table->string('actor_id', 255)->nullable()->index();
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('strategy', 32)->nullable();
            $table->unsignedInteger('total')->nullable();
            $table->json('counts_json')->nullable();
            $table->string('target_hash', 64)->nullable()->index();
            $table->string('target_ref', 255)->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->string('justification', 500)->nullable();
            $table->timestamps();
            $table->index(['event_type', 'status_code', 'id'], 'pra_audit_event_status_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pii_redactor_admin_audit_events');
    }
};
