<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Test mirror of database/migrations/2026_05_03_000001_change_embedding_cache_unique_to_composite.php.
 *
 * Same constraint shift on the SQLite test schema: drop the
 * single-column UNIQUE on `text_hash` and add the composite UNIQUE on
 * `(text_hash, provider, model)`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('embedding_cache', function (Blueprint $table) {
            $table->dropUnique('embedding_cache_text_hash_unique');
            $table->unique(
                ['text_hash', 'provider', 'model'],
                'embedding_cache_text_hash_provider_model_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('embedding_cache', function (Blueprint $table) {
            $table->dropUnique('embedding_cache_text_hash_provider_model_unique');
            $table->unique('text_hash', 'embedding_cache_text_hash_unique');
        });
    }
};
