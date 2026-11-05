<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['conversations', 'widget_sessions'] as $table) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->json('reasoning_state')->nullable());
        }
    }

    public function down(): void
    {
        foreach (['conversations', 'widget_sessions'] as $table) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn('reasoning_state'));
        }
    }
};
