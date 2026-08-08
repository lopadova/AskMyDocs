<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->foreignId('agent_run_id')
                ->nullable()
                ->unique()
                ->after('conversation_id')
                ->constrained('agent_runs')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropUnique('messages_agent_run_id_unique');
        });
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('agent_run_id');
        });
    }
};
