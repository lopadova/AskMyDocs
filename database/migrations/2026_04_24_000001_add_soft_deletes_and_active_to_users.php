<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PR7 (Phase F2) — soft-delete + is_active on users.
 *
 * Why: the admin surface needs to deactivate users without cascading
 * deletes across conversations, chat_logs, memberships. Soft delete
 * keeps the row (and its history) intact while hiding it from default
 * reads; is_active gates login independently of trashed state.
 *
 * R2 compliance: after this migration, every query against the users
 * table outside the auth layer MUST be explicit about withTrashed() /
 * onlyTrashed() when deleted rows are relevant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('password');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn('is_active');
        });
    }
};
