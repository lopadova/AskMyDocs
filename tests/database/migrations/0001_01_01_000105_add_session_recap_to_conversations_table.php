<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Test-environment mirror of
 * `2026_10_03_000003_add_session_recap_to_conversations_table`.
 * Keep the column shape 1:1 with the production migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->json('session_recap')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropColumn('session_recap');
        });
    }
};
