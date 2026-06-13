<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SQLite-compatible mirror of
// database/migrations/2026_06_13_000002_add_autowiki_columns_to_kb_analysis_settings.php
// Runs after the kb_analysis_settings create mirror (000050).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kb_analysis_settings', function (Blueprint $table): void {
            $table->boolean('autowiki_enabled')->nullable()->after('delete_enabled');
            $table->boolean('autowiki_canonical')->nullable()->after('autowiki_enabled');
            $table->boolean('autowiki_non_canonical')->nullable()->after('autowiki_canonical');
        });
    }

    public function down(): void
    {
        Schema::table('kb_analysis_settings', function (Blueprint $table): void {
            $table->dropColumn(['autowiki_enabled', 'autowiki_canonical', 'autowiki_non_canonical']);
        });
    }
};
