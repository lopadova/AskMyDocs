<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('widget_keys', function (Blueprint $table) {
            $table->boolean('user_auth_enabled')->default(false);
            $table->string('identity_secret_hash')->nullable();
            $table->unsignedInteger('identity_credential_version')->default(0);
            $table->unsignedInteger('identity_access_epoch')->default(0);
        });
        Schema::create('widget_identities', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 50)->index();
            $table->foreignId('widget_key_id')->constrained('widget_keys')->cascadeOnDelete();
            $table->string('project_key', 120);
            $table->char('subject_hash', 64);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->unique(['widget_key_id', 'subject_hash'], 'uq_widget_identity_key_subject');
        });
        Schema::table('widget_sessions', function (Blueprint $table) {
            $table->foreignId('widget_identity_id')
                ->nullable()
                ->constrained('widget_identities')
                ->nullOnDelete();
            $table->index(['widget_identity_id', 'created_at'], 'idx_widget_sessions_identity_created');
        });
        Schema::table('widget_session_tokens', function (Blueprint $table) {
            $table->unsignedInteger('identity_access_epoch')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('widget_session_tokens', function (Blueprint $table) {
            $table->dropColumn('identity_access_epoch');
        });
        Schema::table('widget_sessions', function (Blueprint $table) {
            $table->dropIndex('idx_widget_sessions_identity_created');
            $table->dropConstrainedForeignId('widget_identity_id');
        });
        Schema::dropIfExists('widget_identities');
        Schema::table('widget_keys', function (Blueprint $table) {
            $table->dropColumn([
                'user_auth_enabled',
                'identity_secret_hash',
                'identity_credential_version',
                'identity_access_epoch',
            ]);
        });
    }
};
