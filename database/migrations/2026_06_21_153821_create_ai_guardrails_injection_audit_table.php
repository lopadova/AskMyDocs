<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_guardrails_injection_audit', function (Blueprint $table): void {
            $table->id();
            $table->text('prompt');
            $table->boolean('blocked')->index();
            $table->string('rule_id')->nullable()->index();
            $table->string('principal_id')->nullable()->index();
            $table->string('ruleset_version')->nullable()->index();
            $table->json('errored_rule_ids')->nullable();
            $table->unsignedInteger('match_start')->nullable();
            $table->unsignedInteger('match_end')->nullable();
            $table->timestamp('occurred_at')->index();
            // Append-only: intentionally NO updated_at — rows are never modified in place.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_guardrails_injection_audit');
    }
};
