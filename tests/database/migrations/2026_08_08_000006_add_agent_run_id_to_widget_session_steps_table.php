<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('widget_session_steps', function (Blueprint $table): void {
            $table->foreignId('agent_run_id')
                ->nullable()
                ->unique()
                ->constrained('agent_runs')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('widget_session_steps', function (Blueprint $table): void {
            $table->dropUnique(['agent_run_id']);
        });
        Schema::table('widget_session_steps', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('agent_run_id');
        });
    }
};
