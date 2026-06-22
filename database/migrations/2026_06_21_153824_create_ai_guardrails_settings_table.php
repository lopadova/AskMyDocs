<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_guardrails_settings', function (Blueprint $table): void {
            $table->id();
            // Mutable current-state table (NOT append-only): one row per overridable dotted key.
            $table->string('key')->unique();
            // NOT NULL: every persisted override is a real JSON document, so SQL NULL is never valid
            // here. (The fail-safe "ignore null/type-mismatched overrides" policy is enforced in the
            // app layer — a JSON `null` document is still a valid non-NULL value at the DB level.)
            $table->json('value');
            // created_at defaults to the DB clock on insert and is never written again, so it stays
            // the true first-write time; updated_at is set explicitly by the store on every upsert.
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_guardrails_settings');
    }
};
