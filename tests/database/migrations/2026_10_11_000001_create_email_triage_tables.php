<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_triage_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 50)->index();
            $table->unsignedBigInteger('connector_installation_id')->index();
            $table->unsignedInteger('revision');
            $table->boolean('enabled')->default(false);
            $table->string('model', 100);
            $table->json('rules');
            $table->timestamps();
            $table->unique(['tenant_id', 'connector_installation_id', 'revision'], 'triage_profile_revision');
        });
        Schema::create('email_triage_messages', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 50)->index();
            $table->unsignedBigInteger('connector_installation_id')->index();
            $table->string('identity_hash', 64);
            $table->string('project_key');
            $table->text('source_path')->nullable();
            $table->string('disk', 100)->nullable();
            $table->text('prefix')->nullable();
            $table->text('original_path')->nullable();
            $table->string('original_hash', 64)->nullable();
            $table->text('title')->nullable();
            $table->json('metadata');
            $table->json('manifest');
            $table->unsignedBigInteger('profile_id');
            $table->string('status', 30)->default('awaiting_files')->index();
            $table->unsignedBigInteger('evaluation_id')->nullable();
            $table->unsignedBigInteger('knowledge_document_id')->nullable();
            $table->timestamp('email_date')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'connector_installation_id', 'identity_hash'], 'triage_message_identity');
        });
        Schema::create('email_triage_evaluations', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 50)->index();
            $table->unsignedBigInteger('message_id')->index();
            $table->unsignedBigInteger('profile_id');
            $table->string('evaluation_key', 64)->unique();
            $table->string('input_hash', 64);
            $table->string('status', 30)->default('waiting')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->json('result')->nullable();
            $table->json('coverage')->nullable();
            $table->json('tags')->nullable();
            $table->text('card')->nullable();
            $table->json('usage')->nullable();
            $table->double('latency_ms')->nullable();
            $table->string('error_code', 100)->nullable();
            $table->string('index_status', 30)->default('waiting');
            $table->unsignedBigInteger('indexed_document_id')->nullable();
            $table->timestamp('indexed_at')->nullable();
            $table->timestamp('index_queued_at')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('retry_at')->nullable();
            $table->timestamps();
        });
        Schema::create('email_triage_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 50)->index();
            $table->unsignedBigInteger('connector_installation_id')->index();
            $table->unsignedBigInteger('profile_id');
            $table->string('status', 30)->default('running')->index();
            $table->json('filters');
            $table->unsignedBigInteger('cursor')->default(0);
            $table->unsignedBigInteger('cutoff_id');
            $table->unsignedInteger('selected')->default(0);
            $table->unsignedInteger('unavailable')->default(0);
            $table->timestamps();
        });
        Schema::create('email_triage_campaign_items', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 50)->index();
            $table->unsignedBigInteger('campaign_id')->index();
            $table->unsignedBigInteger('knowledge_document_id');
            $table->unsignedBigInteger('message_id')->nullable();
            $table->string('status', 30)->default('waiting');
            $table->string('error_code', 100)->nullable();
            $table->timestamps();
            $table->unique(['campaign_id', 'knowledge_document_id'], 'triage_campaign_document');
        });
    }

    public function down(): void
    {
        foreach (['email_triage_campaign_items', 'email_triage_campaigns', 'email_triage_evaluations', 'email_triage_messages', 'email_triage_profiles'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
