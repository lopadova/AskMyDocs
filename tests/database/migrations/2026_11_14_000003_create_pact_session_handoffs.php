<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pact_session_handoffs', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');
            $table->unsignedBigInteger('parent_run_id');
            $table->unsignedBigInteger('child_run_id');
            $table->unsignedBigInteger('conversation_id');
            $table->unsignedBigInteger('execution_id');
            $table->string('action_id');
            $table->json('handoff');
            $table->string('status')->default('waiting');
            $table->timestamps();
            $table->unique(['parent_run_id', 'action_id']);
            $table->unique('child_run_id');
        });
    }
    public function down(): void { Schema::dropIfExists('pact_session_handoffs'); }
};
