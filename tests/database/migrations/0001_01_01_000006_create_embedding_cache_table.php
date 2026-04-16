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
            $table->string('text_hash', 64)->unique();
            $table->string('provider', 64)->index();
            $table->string('model', 128)->index();
            $table->text('embedding'); // pgvector replaced by JSON text for sqlite tests
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('last_used_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('embedding_cache');
    }
};
