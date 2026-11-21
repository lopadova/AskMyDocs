<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pact_client_subjects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('tenant_id', 50);
            $table->string('project_key', 120);
            $table->unique(['user_id', 'tenant_id', 'project_key'], 'pact_client_subject_scope');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pact_client_subjects');
    }
};
