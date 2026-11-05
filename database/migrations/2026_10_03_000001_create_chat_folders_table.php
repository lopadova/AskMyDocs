<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * User-created folders that group chat sessions in the Sessions workspace.
 *
 * A folder is PRIVATE to one user inside one tenant: it carries no
 * sharing, no nesting and no team visibility, because the only job it has
 * is to let one person file their own threads by topic ("Issue #42",
 * "Refactor auth"). Conversations reference it through a nullable
 * `conversations.chat_folder_id`, so a thread belongs to at most one
 * folder and "unfiled" is the natural default rather than a magic row.
 *
 * Shape mirrors {@see \App\Models\ChatFilterPreset} — the established
 * per-user, per-tenant chat-UI object: tenant_id + user_id + a unique
 * name, authorization enforced in the service rather than by a policy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_folders', function (Blueprint $table): void {
            $table->bigIncrements('id');
            // R31: tenant_id is mandatory on every tenant-aware table.
            $table->string('tenant_id', 50)->default('default')->index();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 120);
            // Written on create and used as the sidebar ordering key. There
            // is deliberately no reorder UI yet; shipping the column now
            // keeps the ordering contract stable when one lands.
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            // R31: composite uniques START with tenant_id. Two tenants — and
            // two users inside one tenant — may legitimately pick the same
            // intuitive folder name, so uniqueness is per (tenant, user).
            $table->unique(['tenant_id', 'user_id', 'name'], 'uq_chat_folders_tenant_user_name');
            // The sidebar's only query: this user's folders, in order.
            $table->index(['tenant_id', 'user_id', 'position'], 'chat_folders_tenant_user_position_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_folders');
    }
};
