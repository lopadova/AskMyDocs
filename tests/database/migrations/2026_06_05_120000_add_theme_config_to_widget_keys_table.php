<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror SQLite di
 * database/migrations/..._add_theme_config_to_widget_keys_table.php
 * (tests/database/migrations rispecchia il set di produzione — vedi
 * Tests\TestCase::defineDatabaseMigrations). Nessuna colonna vector → copia
 * identica alla migration di produzione (SQLite mappa json su text).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('widget_keys', function (Blueprint $table) {
            $table->json('theme_config')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('widget_keys', function (Blueprint $table) {
            $table->dropColumn('theme_config');
        });
    }
};
