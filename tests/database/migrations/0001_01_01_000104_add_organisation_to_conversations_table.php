<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Test-environment mirror of
 * `2026_10_03_000002_add_organisation_to_conversations_table`.
 * Keep the column shapes 1:1 with the production migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->foreignId('chat_folder_id')->nullable()
                ->constrained('chat_folders')->nullOnDelete();
            $table->timestamp('pinned_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->string('importance', 16)->default('normal');

            $table->index(['tenant_id', 'user_id', 'archived_at'], 'conversations_tenant_user_archived_idx');
            $table->index('chat_folder_id', 'conversations_chat_folder_idx');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropIndex('conversations_chat_folder_idx');
            $table->dropIndex('conversations_tenant_user_archived_idx');
            $table->dropConstrainedForeignId('chat_folder_id');
            $table->dropColumn(['pinned_at', 'archived_at', 'importance']);
        });
    }
};
