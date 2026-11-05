<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->unsignedBigInteger('last_read_message_id')->default(0);
        });

        // Existing history predates unread tracking. Do not turn every old
        // conversation into a notification on deployment.
        DB::table('conversations')->update(['last_read_message_id' => DB::raw(
            "(SELECT COALESCE(MAX(messages.id), 0) FROM messages WHERE messages.conversation_id = conversations.id AND messages.role = 'assistant')",
        )]);
    }

    public function down(): void
    {
        Schema::table('conversations', fn (Blueprint $table) => $table->dropColumn('last_read_message_id'));
    }
};
