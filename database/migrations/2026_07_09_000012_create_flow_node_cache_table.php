<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('flow_node_cache')) {
            return;
        }

        Schema::create('flow_node_cache', function (Blueprint $table): void {
            $table->id();
            $table->string('content_hash', 64)->unique(); // sha256 of {type, inputs, config}
            $table->string('node_type');
            $table->json('outputs');                       // redacted like every other persisted payload
            $table->json('business_impact')->nullable();
            $table->timestampTz('expires_at')->nullable(); // null = never expires
            $table->timestampsTz();

            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_node_cache');
    }
};
