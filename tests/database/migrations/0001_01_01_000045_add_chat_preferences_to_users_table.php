<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Test-suite mirror of the production
 * `2026_05_22_120000_add_chat_preferences_to_users_table` migration.
 * The test suite runs against SQLite; the column shape is the same
 * because both drivers support nullable JSON.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->json('chat_preferences')->nullable()->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('chat_preferences');
        });
    }
};
