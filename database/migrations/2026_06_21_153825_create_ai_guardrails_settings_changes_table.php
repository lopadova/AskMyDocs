<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_guardrails_settings_changes', function (Blueprint $table): void {
            $table->id();
            $table->string('actor_id')->nullable()->index();
            $table->string('setting_key')->index();
            $table->json('old_value')->nullable();
            $table->json('new_value')->nullable();
            $table->timestamp('occurred_at')->index();
            // Append-only: intentionally NO updated_at — rows are never modified in place.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_guardrails_settings_changes');
    }
};
