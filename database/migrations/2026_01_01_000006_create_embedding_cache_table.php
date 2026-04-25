<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $isPgsql = DB::getDriverName() === 'pgsql';

        Schema::create('embedding_cache', function (Blueprint $table) use ($isPgsql) {
            $table->id();
            $table->string('text_hash', 64)->unique();       // SHA-256 of the input text
            $table->string('provider', 64)->index();          // openai, gemini, etc.
            $table->string('model', 128)->index();            // text-embedding-3-small, etc.
            // Production = pgvector. Non-pgsql connections fall back to
            // text so `php artisan migrate` still works (Playwright CI uses
            // SQLite — the cache is exercised lightly there and the
            // EmbeddingCacheService runtime check noops on non-pgsql).
            if ($isPgsql) {
                $table->vector('embedding', dimensions: 1536);
            } else {
                $table->text('embedding')->nullable();
            }
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('last_used_at')->useCurrent();  // LRU tracking
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('embedding_cache');
    }
};
