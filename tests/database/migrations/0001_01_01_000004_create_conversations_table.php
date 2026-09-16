<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // Mirrors production (2026_01_01_000004): NULLABLE with no
            // default. The old NOT NULL + 'Nuova chat' default diverged
            // from the real schema, so `POST /conversations` — which
            // inserts `title => null` and lets the auto-title fill it in
            // later — could not be tested under Testbench at all (R9).
            $table->string('title')->nullable();
            $table->string('project_key', 120)->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
