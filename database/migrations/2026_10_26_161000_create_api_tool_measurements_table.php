<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('api_tool_measurements',function (Blueprint $t): void {
            $t->id(); $t->string('tenant_id'); $t->uuid('agent_run_id'); $t->string('provider'); $t->string('model');
            $t->unsignedInteger('prompt_tokens')->nullable(); $t->unsignedInteger('completion_tokens')->nullable();
            $t->unsignedInteger('latency_ms'); $t->timestamp('created_at'); $t->index(['tenant_id','agent_run_id']);
        });
    }
    public function down(): void { Schema::dropIfExists('api_tool_measurements'); }
};
