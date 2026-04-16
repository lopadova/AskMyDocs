<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('embedding_cache', function (Blueprint $table) {
            $table->id();
            $table->string('text_hash', 64)->unique();       // SHA-256 of the input text
            $table->string('provider', 64)->index();          // openai, gemini, etc.
            $table->string('model', 128)->index();            // text-embedding-3-small, etc.
            $table->vector('embedding', dimensions: 1536);    // cached vector
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('last_used_at')->useCurrent();  // LRU tracking
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('embedding_cache');
    }
};
