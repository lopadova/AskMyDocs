<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror SQLite di database/migrations/..._create_widget_keys_table.php
 * (tests/database/migrations rispecchia il set di produzione — vedi
 * Tests\TestCase::defineDatabaseMigrations). Nessuna colonna vector → copia
 * identica alla migration di produzione.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('widget_keys', function (Blueprint $table) {
            $table->id();

            $table->string('tenant_id', 50)->default('default')->index();
            $table->string('project_key', 120);

            $table->string('public_key', 64)->unique();
            $table->string('secret_hash', 255)->nullable();

            $table->json('allowed_origins')->nullable();
            $table->unsignedInteger('rate_limit')->default(60);
            $table->string('skill', 100)->default('askmydocs-assistant@1');

            $table->boolean('is_active')->default(true);
            $table->string('label', 120)->nullable();
            $table->timestamp('last_used_at')->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'project_key', 'label'], 'uq_widget_keys_tenant_project_label');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('widget_keys');
    }
};
