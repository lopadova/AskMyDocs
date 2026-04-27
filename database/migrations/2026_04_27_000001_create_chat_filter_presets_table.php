<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T2.9 — `chat_filter_presets` table for user-saved filter combos.
 *
 * Each row is owned by exactly one user (cascade-delete on user removal).
 * The unique `(user_id, name)` index prevents two presets sharing the
 * same display name within one account but allows different users to
 * pick the same name independently. The `filters` column stores a
 * serialised RetrievalFilters payload (the same shape the chat
 * controller's `KbChatRequest::toFilters()` builds from).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_filter_presets', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->string('name', 120);
            $table->json('filters');
            $table->timestamps();

            $table->unique(['user_id', 'name'], 'uq_chat_filter_presets_user_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_filter_presets');
    }
};
