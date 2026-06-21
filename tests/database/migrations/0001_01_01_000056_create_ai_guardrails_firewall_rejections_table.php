<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_guardrails_firewall_rejections', function (Blueprint $table): void {
            $table->id();
            $table->text('tool_description');
            $table->string('principal_id')->nullable()->index();
            $table->json('violations');
            $table->timestamp('occurred_at')->index();
            // Append-only: intentionally NO updated_at — rows are never modified in place.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_guardrails_firewall_rejections');
    }
};
