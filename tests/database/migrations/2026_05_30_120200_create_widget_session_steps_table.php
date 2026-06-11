<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror SQLite di database/migrations/..._create_widget_session_steps_table.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('widget_session_steps', function (Blueprint $table) {
            $table->id();

            $table->string('tenant_id', 50)->default('default')->index();
            $table->foreignId('widget_session_id')->constrained('widget_sessions')->cascadeOnDelete();

            $table->unsignedInteger('step_index')->default(0);

            $table->string('kind', 20)->index();
            $table->string('tool', 100)->nullable();

            $table->longText('args_json')->nullable();
            $table->longText('diagnostic_json')->nullable();
            $table->longText('snapshot_in_json')->nullable();
            $table->longText('snapshot_out_json')->nullable();

            $table->unsignedInteger('tokens_in')->nullable();
            $table->unsignedInteger('tokens_out')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();

            $table->timestamps();

            $table->index(['widget_session_id', 'step_index'], 'idx_widget_steps_session_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('widget_session_steps');
    }
};
