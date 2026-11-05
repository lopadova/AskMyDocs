<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('realtime_agent_provider_tools', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('provider');
            $table->string('schema_hash', 64);
            $table->string('provider_tool_id');
            $table->json('definition');
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->unique(['provider', 'schema_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('realtime_agent_provider_tools');
    }
};
