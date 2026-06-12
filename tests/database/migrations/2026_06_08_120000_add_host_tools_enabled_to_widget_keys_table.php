<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror SQLite di
 * database/migrations/..._add_host_tools_enabled_to_widget_keys_table.php
 * (tests/database/migrations rispecchia il set di produzione — vedi
 * Tests\TestCase::defineDatabaseMigrations). Nessuna colonna vector → copia
 * della migration di produzione. `->after()` è omesso: SQLite non supporta il
 * posizionamento di colonna e l'ordine è irrilevante per i test.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('widget_keys', function (Blueprint $table) {
            $table->boolean('host_tools_enabled')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('widget_keys', function (Blueprint $table) {
            $table->dropColumn('host_tools_enabled');
        });
    }
};
