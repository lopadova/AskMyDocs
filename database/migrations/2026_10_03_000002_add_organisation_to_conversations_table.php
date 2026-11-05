<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Session organisation for the Sessions workspace: file into a folder,
 * pin to the top, archive out of the way, flag importance.
 *
 * `pinned_at` / `archived_at` are TIMESTAMPS rather than booleans: they
 * give "most recently pinned first" ordering and an archived-on date for
 * free, index cleanly as NULL-is-false, and need no companion audit
 * column. The HTTP contract is deliberately asymmetric — the request
 * takes booleans (`pinned: true`), the response returns the timestamp,
 * so a client never invents a date.
 *
 * `chat_folder_id` is nullOnDelete, never cascade: deleting a folder must
 * unfile its conversations, not destroy a user's chat history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->foreignId('chat_folder_id')->nullable()->after('project_key')
                ->constrained('chat_folders')->nullOnDelete();
            $table->timestamp('pinned_at')->nullable()->after('chat_folder_id');
            $table->timestamp('archived_at')->nullable()->after('pinned_at');
            $table->string('importance', 16)->default('normal')->after('archived_at');

            // The sidebar's ONLY selective predicate:
            //   WHERE tenant_id = ? AND user_id = ? AND archived_at IS NULL
            $table->index(['tenant_id', 'user_id', 'archived_at'], 'conversations_tenant_user_archived_idx');
            // nullOnDelete sweeps conversations BY chat_folder_id on every
            // folder delete; unindexed that is a full table scan.
            $table->index('chat_folder_id', 'conversations_chat_folder_idx');

            // Deliberately NOT indexed: `pinned_at` and `importance`.
            // Neither is selective, and the ordering CASE cannot use an
            // index anyway — a user's thread count is in the tens.
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
