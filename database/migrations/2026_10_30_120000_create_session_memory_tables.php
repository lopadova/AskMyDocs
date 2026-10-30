<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_memory_observations', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');
            $table->string('project_key')->nullable();
            $table->foreignId('conversation_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('widget_session_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('owner_key', 100);
            $table->unsignedBigInteger('turn_id');
            $table->string('source_key', 64);
            $table->string('namespace');
            $table->json('evidence_json');
            $table->timestamp('acquired_at');
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'owner_key', 'source_key'], 'session_memory_observation_unique');
            $table->index(['tenant_id', 'owner_key', 'expires_at'], 'session_memory_observation_expiry');
        });
        Schema::create('session_memory_facts', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->foreignId('observation_id')->constrained('session_memory_observations')->cascadeOnDelete();
            $table->json('subject_identifiers');
            $table->string('field');
            $table->json('value_json')->nullable();
            $table->json('verification_json');
            $table->json('claim_json')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_memory_facts');
        Schema::dropIfExists('session_memory_observations');
    }
};
