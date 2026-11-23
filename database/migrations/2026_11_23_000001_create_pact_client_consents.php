<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pact_client_consents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('session_hash', 64);
            $table->text('parameters');
            $table->foreignId('subject_id')->nullable()->constrained('pact_client_subjects')->cascadeOnDelete();
            $table->string('active_tenant', 50)->nullable();
            $table->text('passport')->nullable();
            $table->timestamp('expires_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pact_client_consents');
    }
};
